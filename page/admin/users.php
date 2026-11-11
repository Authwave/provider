<?php
use Authwave\Admin\Application\ApplicationCatalogue;
use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Email\EmailRepository;
use Authwave\Model\ApplicationRepository;
use Authwave\Notification\UserNotifications;
use Authwave\Security\{Action, Audit, Sudo};
use Authwave\UI\DeletionPrompt;
use Authwave\Session\FlashSession;
use Authwave\User\UserRepository;
use GT\Dom\Element;
use GT\Dom\HTMLDocument;
use GT\DomTemplate\Binder;
use GT\DomValidation\{Validator, ValidationException};
use GT\Http\{Request, Response};
use GT\Http\Uri;
use GT\Http\ResponseStatusException\ClientError\{HttpNotFound, HttpUnprocessableEntity};
use GT\Input\Input;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

function go_before(ApplicationRepository $repository, ApplicationCatalogue $catalogue,
	AdminArea $area, Binder $binder, Input $input, Request $request):void {
	$applicationId = $catalogue->selected()->identifier;
	$actor = $area->administrator();
	$deployments = $repository->deployments($actor, $applicationId);
	$submitted = $request->getMethod() === "POST";
	$chosen = $submitted ? $input->get("applicationDeploymentId", Input::DATA_BODY) : null;
	$details = $repository->get($actor, $applicationId);
	$chosen ??= $details->productionApplicationDeploymentId ?? ($deployments[0]->id ?? "");
	$filter = $input->get("userDeployment", Input::DATA_QUERYSTRING) ?? "";

	if(!is_string($filter) || ($filter !== "" && !in_array($filter, array_column($deployments, "id"), true))) {
		throw new HttpUnprocessableEntity();
	}

	foreach(["user-deployment-options" => $chosen, "user-filter-deployments" => $filter] as $template => $selected) {
		$binder->bindListCallback($deployments, function(Element $element, array $fields)use($selected):array {
			$fields["selected"] = $fields["id"] === $selected;
			return $fields;
		}, templateName: $template);
	}

	$binder->bindKeyValue("allDeployments", $filter === "");
	$binder->bindKeyValue("canAddUser", count($deployments) > 0);
}

function go(UserRepository $users, ApplicationCatalogue $catalogue,
	AdminArea $area, Binder $binder, Input $input, Request $request, FlashSession $flash,
	Sudo $sudo, ApplicationRepository $applications, Response $response):void {
	$application = $catalogue->selected();
	$actor = $area->administrator();
	$sudoUntil = $sudo->expiresAt($actor);
	$selectedUser = $input->get("user", Input::DATA_QUERYSTRING);
	$search = $input->get("search", Input::DATA_QUERYSTRING) ?? "";
	$deploymentId = $input->get("userDeployment", Input::DATA_QUERYSTRING) ?? "";
	$sort = $input->get("userSort", Input::DATA_QUERYSTRING) ?? "email";
	$page = $input->get("usersPage", Input::DATA_QUERYSTRING) ?? "1";

	if(($selectedUser !== null && !is_string($selectedUser))
	|| !is_string($search) || !is_string($deploymentId) || !is_string($sort)
	|| !is_string($page) || !ctype_digit($page) || (int)$page < 1) {
		throw new HttpUnprocessableEntity();
	}

	try {
		$total = $users->countSearch($actor, $application->identifier, $search, $deploymentId);
		$page = min((int)$page, max(1, (int)ceil($total / UserRepository::PAGE_SIZE)));
		$rows = $users->search($actor, $application->identifier, $search, $deploymentId, $sort, $page);
	}
	catch(InvalidArgumentException) {
		throw new HttpUnprocessableEntity();
	}

	$details = $rows;

	if($selectedUser !== null && !in_array($selectedUser, array_map(fn($row) => $row->user->id, $rows), true)) {
		$selected = $users->search($actor, $application->identifier, userId: $selectedUser);

		if(!$selected) {
			throw new HttpNotFound();
		}

		$details []= $selected[0];
	}

	$sessions = $users->currentlyLoggedIn($actor, $application->identifier);
	$count = count($sessions);
	$binder->bindKeyValue("activeSessionsLabel", $count === 1 ? "1 active session" : "$count active sessions");
	$currentUsers = [];
	$listedUserIds = [];

	foreach($sessions as $session) {
		if(in_array($session->userId, $listedUserIds, true)) {
			continue;
		}

		$listedUserIds []= $session->userId;
		$currentUsers []= $session;
	}

	$moreUsers = max(0, count($currentUsers) - 25);
	$binder->bindKeyValue("moreCurrentUsers", $moreUsers > 0 ? "$moreUsers more users" : "");
	$binder->bindListCallback(array_slice($currentUsers, 0, 25), function(Element $element, array $fields, int $index)use($currentUsers, $users):array {
		$session = $currentUsers[$index];
		$user = $users->getById($session->userId);

		return [
			"email" => $user->email,
			"emailId" => "session-" . $session->id . "-email",
			"deployment" => $user->deployment->title,
			"loginDatetime" => $session->createdAt->format(DATE_ATOM),
			"lastLogin" => $session->createdAt->format("Y-m-d H:i"),
		];
	}, templateName: "current-sessions");

	$failures = $users->latestFailures($actor, $application->identifier);
	foreach(["users" => $rows, "user-details" => $details, "failed-users" => $failures] as $template => $items) {
		$binder->bindListCallback($items, function(Element $element, array $fields, int $index)use($items, $template, $users, $actor, $binder, $sudo):array {
			$activity = $items[$index];
			$user = $activity->user;

			if($template === "user-details") {
				$logins = $users->recentLogins($actor, $user);
				$binder->bindListCallback($logins, function(Element $element, DateTimeImmutable $login):array {
					return ["datetime" => $login->format(DATE_ATOM),
						"timestamp" => $login->format("Y-m-d H:i:s T")];
				}, context: $element, templateName: "user-logins");
				$binder->bindList($users->deploymentsForEmail($actor, $user),
					context: $element, templateName: "email-deployments");
			}

			$syncMessage = "User creation was not confirmed. ";

			if($user->clientNotificationAction === "user-deleted") {
				$syncMessage = "Deletion was not confirmed. This user has been retained. ";
			}

			$syncMessage .= $user->clientNotificationStatus === 0 ? "The client could not be reached."
				: "The client returned HTTP " . $user->clientNotificationStatus . " without confirming the change.";

			return [
				"email" => $user->email,
				"emailId" => $template . "-" . $user->id . "-email",
				"id" => $user->id,
				"syncError" => $user->clientNotificationStatus !== null
					&& !$user->clientNotificationAcknowledged
					&& ($user->clientNotificationAction === "user-deleted" || $activity->lastLoginAt === null),
				"detailsId" => "user-details-" . $user->id,
				"headingId" => "user-heading-" . $user->id,
				"detailEmailId" => "user-detail-email-" . $user->id,
				"deleteName" => $sudo->obscure($user->email, "email"),
				"authentication" => $user->hash === null ? "Email security code" : "Password and email security code",
				"hasLogins" => $activity->loginCount > 0,
				"syncMessage" => $syncMessage,
				"deployment" => $user->deployment->title,
				"loginCount" => $activity->loginCount,
				"createdDetail" => $user->createdAt?->format("Y-m-d H:i:s T") ?? "—",
				"created" => $user->createdAt?->format("Y-m-d H:i") ?? "—",
				"lastLogin" => $activity->lastLoginAt?->format("Y-m-d H:i") ?? "—",
				"lastFailure" => $activity->lastFailureAt?->format("Y-m-d H:i") ?? "—",
				"failureDatetime" => $activity->lastFailureAt?->format(DATE_ATOM) ?? "",
			];
		}, templateName: $template);
	}

	$binder->bindKeyValue("deleteSudoUntil", $sudoUntil);
	$binder->bindKeyValue("canDeleteUser", $sudoUntil > 0);
	$confirmationId = $input->get("confirm-delete", Input::DATA_QUERYSTRING);

	if($confirmationId !== null) {
		$target = is_string($confirmationId) ? $users->getById($confirmationId) : null;

		if($target === null) {
			throw new HttpUnprocessableEntity();
		}

		$applications->get($actor, $target->deployment->applicationId);
		$prompt = new DeletionPrompt($target->id, $target->email, "/admin/users/");

		if($sudoUntil === 0) {
			$return = (new Uri("/admin/users/"))->withQuery($request->getUri()->getQuery())
				->withQueryValue("application", $target->deployment->applicationId)
				->withQueryValue("user", $target->id)->withoutQueryValue("confirm-delete");
			$response->redirect($sudo->request($actor, $target->id, (string)$return, "delete"));
			return;
		}

		$binder->bindKeyValue("deleteUserId", $target->id);
		$binder->bindKeyValue("userDeleteMessage", $prompt->message);
	}

	$binder->bindKeyValue("showUserConfirmation", $confirmationId !== null);
	$binder->bindKeyValue("hasUsers", count($rows) > 0);
	$binder->bindKeyValue("hasFailures", count($failures) > 0);
	$binder->bindKeyValue("userCount", "$total users · Page $page");
	$binder->bindKeyValue("applicationId", $application->identifier);
	$binder->bindKeyValue("search", $search);

	foreach(["email", "created", "logins"] as $option) {
		$binder->bindKeyValue($option . "Sort", $sort === $option);
	}

	$binder->bindKeyValue("previousPage", (int)$page > 1
		? (string)$request->getUri()->withQueryValue("usersPage", (int)$page - 1) : "");
	$binder->bindKeyValue("nextPage", (int)$page * UserRepository::PAGE_SIZE < $total
		? (string)$request->getUri()->withQueryValue("usersPage", (int)$page + 1) : "");
	$submitted = $request->getMethod() === "POST";
	$email = $submitted ? $input->get("email", Input::DATA_BODY) : "";
	$binder->bindKeyValue("email", is_string($email) ? $email : "");
	$binder->bindKeyValue("setPassword", $submitted && $input->get("setPassword", Input::DATA_BODY) === "yes");
	$binder->bindKeyValue("emailInstructions", !$submitted || $input->get("emailInstructions", Input::DATA_BODY) === "yes");
	$binder->bindKeyValue("notificationResult", $flash->getFlash("notification_result") ?? "");
	$binder->bindKeyValue("userSuccess", $flash->getFlash("user_success") ?? "");
	$binder->bindKeyValue("userError", $flash->getFlash("user_error") ?? "");
}

function do_options():void {
	// Re-render conditional authentication fields when scripting is unavailable.
}

function do_create(Input $input, Request $request, Response $response, HTMLDocument $document,
	ApplicationCatalogue $catalogue, AdminArea $area, UserRepository $users,
	EmailRepository $email, FlashSession $flash, Audit $audit, UserNotifications $notifications):void {
	if($request->getMethod() !== "POST") {
		throw new HttpUnprocessableEntity();
	}

	$applicationId = $catalogue->selected()->identifier;
	$validator = new Validator();

	try {
		$fields = [];

		foreach(["email", "applicationDeploymentId", "password", "setPassword", "emailInstructions"] as $name) {
			$value = $input->get($name, Input::DATA_BODY);

			if($value !== null && !is_string($value)) {
				throw new InvalidArgumentException("Submit a single value for each field.");
			}

			$fields[$name] = $value;
		}

		$form = $document->querySelector("#add-user-form")->cloneNode(true);
		$password = $form->querySelector("[data-enable-when]");

		if($fields["setPassword"] === "yes") {
			$password->removeAttribute("disabled");
		}
		else {
			$password->remove();
		}

		$validator->validate($form, $fields);
		$user = $users->createForAdministrator($area->administrator(), $applicationId,
			$fields["applicationDeploymentId"], $fields["email"],
			$fields["setPassword"] === "yes" ? $fields["password"] : null,
			$fields["emailInstructions"] === "yes");
	}
	catch(ValidationException) {
		$messages = [];

		foreach($validator->getLastErrorList() as $name => $message) {
			$label = $document->querySelector('[name="' . $name . '"]')->closest("label")->querySelector("span")->textContent;
			$messages []= "$label: $message";
		}

		$flash->setFlash(implode(" ", $messages), "user_error");
		$audit->create(Action::USER_CREATION_REJECTED, ["applicationId" => $applicationId], $area->administrator());
		return;
	}
	catch(InvalidArgumentException $error) {
		$flash->setFlash($error->getMessage(), "user_error");
		$audit->create(Action::USER_CREATION_REJECTED, ["applicationId" => $applicationId], $area->administrator());
		return;
	}

	$message = "User created.";

	$notified = $notifications->send($user, $area->administrator());

	if($fields["emailInstructions"] === "yes") {
		$message .= " Login instructions have been queued.";

		try {
			$email->sendScheduled();
		}
		catch(TransportExceptionInterface) {
			$message .= " Email delivery could not be completed.";
		}
	}

	if($notified === false) {
		$message .= " Client notification could not be confirmed. Use Redeliver in All users to try again.";
	}

	$flash->setFlash($message, "user_success");
	$response->redirect("/admin/users/#add-user");
}

function do_redeliver(Input $input, Request $request, Response $response, UserRepository $users,
	UserNotifications $notifications, AdminArea $area, FlashSession $flash,
	Sudo $sudo, ApplicationRepository $applications):void {
	$id = $input->get("id", Input::DATA_BODY);
	$user = is_string($id) ? $users->getById($id) : null;

	if($request->getMethod() !== "POST" || $user === null) {
		throw new HttpUnprocessableEntity();
	}

	$actor = $area->administrator();

	if($user->clientNotificationAction === "user-deleted" && $sudo->expiresAt($actor) === 0) {
		$applications->get($actor, $user->deployment->applicationId);
		$return = (new Uri("/admin/users/"))->withQuery($request->getUri()->getQuery())
			->withQueryValue("application", $user->deployment->applicationId)
			->withQueryValue("user", $user->id)->withoutQueryValue("confirm-delete");
		$response->redirect($sudo->request($actor, $user->id, (string)$return, "delete"));
		return;
	}

	$delivered = $notifications->send($user, $area->administrator());
	$flash->setFlash($delivered ? ($user->clientNotificationAction === "user-deleted" ? "User deleted." : "Client notification delivered.")
		: "Client notification could not be confirmed. You can try Redeliver again.", "notification_result");
	$destination = $request->getUri()->withFragment("all-users");

	if($delivered && $user->clientNotificationAction === "user-deleted") {
		$destination = $destination->withoutQueryValue("user");
	}

	$response->redirect($destination);
}

function do_delete_user(Input $input, Request $request, Response $response, UserRepository $users,
	ApplicationRepository $applications, UserNotifications $notifications, AdminArea $area,
	FlashSession $flash, Audit $audit, Sudo $sudo):void {
	$id = $input->get("id", Input::DATA_BODY);
	$user = is_string($id) ? $users->getById($id) : null;

	if($request->getMethod() !== "POST" || $user === null) {
		throw new HttpUnprocessableEntity();
	}

	$actor = $area->administrator();
	$applications->get($actor, $user->deployment->applicationId);
	$prompt = new DeletionPrompt($user->id, $user->email, "/admin/users/");

	if($sudo->expiresAt($actor) === 0) {
		$return = (new Uri("/admin/users/"))->withQuery($request->getUri()->getQuery())
			->withQueryValue("application", $user->deployment->applicationId)
			->withQueryValue("user", $user->id)->withoutQueryValue("confirm-delete");
		$response->redirect($sudo->request($actor, $user->id, (string)$return, "delete"));
		return;
	}

	if(!$prompt->isConfirmed($input->get("confirmDelete", Input::DATA_BODY))) {
		$audit->create(Action::DELETE_CONFIRMATION_REQUESTED, ["userId" => $user->id], $actor);
		$response->redirect($prompt->confirmationUrl);
		return;
	}

	try {
		$deleted = $notifications->sendDeletion($user, $actor);
		$message = $deleted ? "User deleted."
			: "Deletion could not be confirmed. The user has been retained. Open Edit to redeliver the deletion.";
	}
	catch(InvalidArgumentException $error) {
		$audit->create(Action::USER_DELETION_REJECTED, ["userId" => $user->id], $actor);
		$message = $error->getMessage();
	}

	$flash->setFlash($message, "notification_result");
	$response->redirect("/admin/users/");
}
