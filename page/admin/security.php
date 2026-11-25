<?php
use Authwave\Admin\Application\ApplicationCatalogue;
use Authwave\Admin\Dashboard\{DashboardRepository, ReportingPeriod};
use Authwave\Admin\Audit\AuditRepository;
use Authwave\Admin\Security\SecurityScore;
use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Model\ApplicationRepository;
use Authwave\Model\ApplicationSessionPolicy;
use Authwave\User\UserRepository;
use Authwave\Session\FlashSession;
use Authwave\Security\Action;
use Authwave\Security\Sudo;
use GT\Dom\Element;
use GT\Dom\HTMLDocument;
use GT\DomTemplate\Binder;
use GT\DomTemplate\ListElementCollection;
use GT\Http\ResponseStatusException\ClientError\HttpUnprocessableEntity;
use GT\Http\Request;
use GT\Http\Response;
use GT\Http\Uri;
use GT\Input\Input;

function go_before(Request $request, Response $response, AdminArea $area,
	ApplicationCatalogue $applications, Sudo $sudo, Input $input):void {
	if($request->getMethod() !== "POST") {
		return;
	}

	$actor = $area->administrator();

	if($sudo->expiresAt($actor) === 0) {
		$return = (new Uri("/admin/security/"))->withQuery($request->getUri()->getQuery())
			->withQueryValue("application", $applications->selected()->identifier);

		if(in_array($input->get("do", Input::DATA_BODY),
			["enable_sudo", "save_sessions", "confirm_revoke", "revoke_sessions"], true)) {
			$return = $return->withFragment("sessions");
		}

		$response->redirect($sudo->request($actor, "security-settings", (string)$return, "update"));
	}

	if($input->get("do", Input::DATA_BODY) === null) {
		$response->redirect($request->getUri());
	}
}

function go(
	Binder $binder,
	HTMLDocument $document,
	ListElementCollection $lists,
	Input $input,
	DashboardRepository $dashboardRepository,
	AuditRepository $auditRepository,
	ApplicationCatalogue $applicationCatalogue,
	ApplicationRepository $applicationRepository,
	AdminArea $adminArea,
	UserRepository $users,
	FlashSession $flash,
	Sudo $sudo,
):void {
	$from = $input->get("from", Input::DATA_QUERYSTRING) ?? gmdate("Y-m-d", strtotime("-29 days"));
	$to = $input->get("to", Input::DATA_QUERYSTRING) ?? gmdate("Y-m-d");
	$deploymentId = $input->get("deployment", Input::DATA_QUERYSTRING) ?? "";
	$eventFilter = $input->get("event", Input::DATA_QUERYSTRING) ?? "";
	$page = $input->get("eventPage", Input::DATA_QUERYSTRING) ?? "1";

	if(!is_string($from) || !is_string($to) || !is_string($deploymentId)
	|| !is_string($eventFilter) || !is_string($page) || !ctype_digit($page)) {
		throw new HttpUnprocessableEntity();
	}

	$applicationId = $applicationCatalogue->selected()->identifier;
	$actor = $adminArea->administrator();
	$deployments = $applicationRepository->deployments($actor, $applicationId);
	if($deploymentId !== ""
	&& !in_array($deploymentId, array_column($deployments, "id"), true)) {
		throw new HttpUnprocessableEntity();
	}

	try {
		$period = new ReportingPeriod("custom", $from, $to);
		$page = (int)$page;
		$activity = $dashboardRepository->activity(
			$actor,
			$applicationId,
			$period,
			$eventFilter,
			$page,
			$deploymentId === ""
				? null : $deploymentId,
		);
	}
	catch(InvalidArgumentException) {
		throw new HttpUnprocessableEntity();
	}

	$auditFrom = $input->get("auditFrom", Input::DATA_QUERYSTRING) ?? gmdate("Y-m-d", strtotime("-29 days"));
	$auditTo = $input->get("auditTo", Input::DATA_QUERYSTRING) ?? gmdate("Y-m-d");
	$auditDeployment = $input->get("auditDeployment", Input::DATA_QUERYSTRING) ?? "";
	$auditEvent = $input->get("auditEvent", Input::DATA_QUERYSTRING) ?? "";
	$auditPage = $input->get("auditPage", Input::DATA_QUERYSTRING) ?? "1";

	if(!is_string($auditFrom) || !is_string($auditTo) || !is_string($auditDeployment)
	|| !is_string($auditEvent) || !is_string($auditPage) || !ctype_digit($auditPage)
	|| ($auditDeployment !== "" && !in_array($auditDeployment, array_column($deployments, "id"), true))) {
		throw new HttpUnprocessableEntity();
	}

	try {
		$auditLog = $auditRepository->log($actor, $applicationId,
			new ReportingPeriod("custom", $auditFrom, $auditTo), $auditEvent, (int)$auditPage, $auditDeployment);
	}
	catch(InvalidArgumentException) {
		throw new HttpUnprocessableEntity();
	}

	$binder->bindKeyValue("auditFrom", $auditFrom);
	$binder->bindKeyValue("auditTo", $auditTo);
	$binder->bindKeyValue("auditDeployment", $auditDeployment);
	$binder->bindKeyValue("auditEvent", $auditEvent);
	$binder->bindKeyValue("auditPage", $auditLog->page);
	$binder->bindKeyValue("allAuditDeployments", $auditDeployment === "");
	$binder->bindListCallback($deployments, function(Element $element, array $fields)use($auditDeployment):array {
		$fields["selected"] = $fields["id"] === $auditDeployment;
		return $fields;
	}, templateName: "audit-deployments");
	$eventOptions = $lists->get($document, "audit-event-options");

	foreach(Action::cases() as $action) {
		$item = $eventOptions->insertListItem();
		$binder->bindKeyValue("auditAction", $action->name, $item);
		$binder->bindKeyValue("auditActionLabel", ucfirst(strtolower(str_replace("_", " ", $action->name))), $item);
		$binder->bindKeyValue("auditActionSelected", $action->name === $auditEvent, $item);
	}

	$auditTemplate = $lists->get($document, "audit-entries");

	foreach($auditLog as $entry) {
		$item = $auditTemplate->insertListItem();
		$binder->bindKeyValue("auditCreatedAt", $entry->createdAt->format("Y-m-d H:i:s"), $item);
		$isYou = $entry->actor === $actor->email;
		$binder->bindKeyValue("auditActor", $isYou ? "You" : ($entry->actor ?? "Deleted user"), $item);
		$binder->bindKeyValue("auditActorId", "audit-" . $entry->id . "-actor", $item);

		if($isYou || $entry->actor === null) {
			$item->querySelector("[data-sudo]")->removeAttribute("data-sudo");
		}

		$binder->bindKeyValue("auditDeploymentTitle", $entry->deployment, $item);
		$binder->bindKeyValue("auditEventLabel", ucfirst(strtolower(str_replace("_", " ", $entry->action->name))), $item);
	}

	$binder->bindKeyValue("emptyAudit", count($auditLog) === 0);
	$binder->bindKeyValue("hasAudit", count($auditLog) > 0);
	$binder->bindKeyValue("hasAuditPagination", count($auditLog) > 0 || $auditLog->hasPrevious());
	$binder->bindKeyValue("hasPreviousAudit", $auditLog->hasPrevious());
	$binder->bindKeyValue("hasNextAudit", $auditLog->hasNext);

	$binder->bindKeyValue("from", $from);
	$binder->bindKeyValue("to", $to);
	$binder->bindKeyValue("event", $eventFilter);
	$binder->bindKeyValue("eventPage", $activity->page);
	$binder->bindKeyValue("applicationId", $applicationId);
	$binder->bindKeyValue("today", gmdate("Y-m-d"));
	$binder->bindKeyValue("allDeployments", $deploymentId === "");
	$binder->bindKeyValue("selectedEventDeployment", $deploymentId);
	$binder->bindListCallback($deployments, function(Element $element, array $fields)use($deploymentId):array {
		$fields["selected"] = $fields["id"] === $deploymentId;
		return $fields;
	}, templateName: "security-deployments");
	$eventTemplate = $lists->get($document, "security-events");

	foreach($activity as $event) {
		$eventLabel = match($event->action) {
			Action::LOGIN_COMPLETED => "Successful login",
			Action::PASSWORD_LOGIN_FAILED => "Password rejected",
			Action::SECURITY_CODE_REJECTED => "Security code rejected",
			Action::SECURITY_CODE_REQUESTED => "Security code requested",
			Action::PASSWORD_CHANGED => "Password changed",
			Action::LOGOUT_REQUESTED => "Logout requested",
			default => throw new LogicException("Unsupported security event."),
		};
		$result = match($event->action) {
			Action::PASSWORD_LOGIN_FAILED, Action::SECURITY_CODE_REJECTED => "Rejected",
			Action::LOGIN_COMPLETED, Action::PASSWORD_CHANGED => "Succeeded",
			default => "Requested",
		};

		$item = $eventTemplate->insertListItem();
		$binder->bindKeyValue("createdAt", $event->createdAt->format("Y-m-d H:i:s"), $item);
		$binder->bindKeyValue("title", $event->deploymentTitle, $item);
		$binder->bindKeyValue("eventLabel", $eventLabel, $item);
		$binder->bindKeyValue("result", $result, $item);
		$binder->bindKeyValue("statusKind", match($result) {
			"Rejected" => "failed", "Succeeded" => "success", default => "info",
		}, $item);
	}

	$binder->bindKeyValue("emptyEvents", count($activity) === 0);
	$binder->bindKeyValue("hasEvents", count($activity) > 0);
	$binder->bindKeyValue("hasEventPagination", count($activity) > 0 || $activity->hasPrevious());
	$binder->bindKeyValue("hasPreviousEvents", $activity->hasPrevious());
	$binder->bindKeyValue("hasNextEvents", $activity->hasNext);
	$query = ["application" => $applicationId, "from" => $from, "to" => $to,
		"deployment" => $deploymentId, "event" => $eventFilter];
	$query += ["auditFrom" => $auditFrom, "auditTo" => $auditTo,
		"auditDeployment" => $auditDeployment, "auditEvent" => $auditEvent, "auditPage" => $auditLog->page];
	$query["eventPage"] = max(1, $page - 1);
	$binder->bindKeyValue("previousEvents", "/admin/security/?" . http_build_query($query) . "#security-events");
	$query["eventPage"] = $page + 1;
	$binder->bindKeyValue("nextEvents", "/admin/security/?" . http_build_query($query) . "#security-events");
	$binder->bindKeyValue("resetEvents", "/admin/security/?" . http_build_query(["application" => $applicationId,
		"auditFrom" => $auditFrom, "auditTo" => $auditTo, "auditDeployment" => $auditDeployment,
		"auditEvent" => $auditEvent, "auditPage" => $auditLog->page]) . "#security-events");
	$query["eventPage"] = $page;
	$query["auditPage"] = max(1, $auditLog->page - 1);
	$binder->bindKeyValue("previousAudit", "/admin/security/?" . http_build_query($query) . "#audit");
	$query["auditPage"] = $auditLog->page + 1;
	$binder->bindKeyValue("nextAudit", "/admin/security/?" . http_build_query($query) . "#audit");
	unset($query["auditFrom"], $query["auditTo"], $query["auditDeployment"], $query["auditEvent"], $query["auditPage"]);
	$binder->bindKeyValue("resetAudit", "/admin/security/?" . http_build_query($query) . "#audit");
	$savedPolicy = $applicationRepository->getSessionPolicyByApplicationId($applicationId);
	$policy = $savedPolicy ?? new ApplicationSessionPolicy($applicationId);
	$sessionDeployment = $input->get("sessionDeployment", Input::DATA_QUERYSTRING) ?? "";

	if(!is_string($sessionDeployment)) {
		throw new HttpUnprocessableEntity();
	}

	try {
		$sessions = $users->activeSessions($actor, $applicationId, $sessionDeployment);
	}
	catch(InvalidArgumentException) {
		throw new HttpUnprocessableEntity();
	}

	$production = null;

	foreach($deployments as $deployment) {
		if($deployment->id === $deployment->application->productionApplicationDeploymentId) {
			$production = $deployment;
		}
	}

	$score = new SecurityScore($savedPolicy, $production,
		$sessionDeployment === "" ? $sessions : $users->activeSessions($actor, $applicationId),
		$applicationCatalogue->selected()->settingsUrl);
	$binder->bindKeyValue("securityGrade", $score->grade);
	$binder->bindKeyValue("securityGradeLabel", "Security grade " . $score->grade);
	$checkTemplate = $lists->get($document, "security-checks");

	foreach($score->checks as $check) {
		$item = $checkTemplate->insertListItem();
		$binder->bindKeyValue("checkDescription", $check->description, $item);
		$binder->bindKeyValue("checkUrl", $check->url, $item);
		$binder->bindKeyValue("checkImpact", match($check->adjustment) {
			1 => "+1", -1 => "−1", default => "No change",
		}, $item);
		$binder->bindKeyValue("checkStatus", match($check->adjustment) {
			1 => "success", -1 => "failed", default => "info",
		}, $item);
	}

	$managedCount = count(array_filter($sessions, fn($entry) => $entry->session->managed));
	$binder->bindKeyValue("maximumLifetimeMinutes", $policy->maximumLifetimeMinutes);
	$binder->bindKeyValue("idleTimeoutMinutes", $policy->idleTimeoutMinutes);
	$binder->bindKeyValue("sessionApplicationName", $applicationCatalogue->selected()->name);
	$binder->bindKeyValue("sessionCount", count($sessions));
	$binder->bindKeyValue("managedSessionLabel", $managedCount === 1
		? "1 managed session" : "$managedCount managed sessions");
	$binder->bindKeyValue("emptySessions", count($sessions) === 0);
	$binder->bindKeyValue("hasSessions", count($sessions) > 0);
	$binder->bindKeyValue("canRevokeSessions", $managedCount > 0);
	$binder->bindKeyValue("sessionSudoActive", $sudo->expiresAt($actor) > 0);
	$binder->bindKeyValue("sessionDeployment", $sessionDeployment);
	$binder->bindKeyValue("allSessionDeployments", $sessionDeployment === "");
	$binder->bindKeyValue("sessionMessage", $flash->getFlash("session_result") ?? "");
	$binder->bindKeyValue("sessionError", $flash->getFlash("session_error") ?? "");
	$binder->bindListCallback($deployments, function(Element $element, array $fields)use($sessionDeployment):array {
		$fields["selected"] = $fields["id"] === $sessionDeployment;
		return $fields;
	}, templateName: "session-deployments");
	$scopeTitle = "All deployments";

	foreach($deployments as $deployment) {
		if($deployment->id === $sessionDeployment) {
			$scopeTitle = $deployment->title;
		}
	}

	$binder->bindKeyValue("sessionScopeTitle", $scopeTitle);
	$sessionUrl = (new Uri("/admin/security/"))->withQueryValue("application", $applicationId)
		->withQueryValue("sessionDeployment", $sessionDeployment);
	$binder->bindKeyValue("sessionUrl", (string)$sessionUrl);
	$template = $lists->get($document, "managed-sessions");
	$confirm = $input->get("confirmSession", Input::DATA_QUERYSTRING);
	$confirmTitle = null;

	if($confirm === "all" && $managedCount > 0) {
		$label = $managedCount === 1 ? "1 managed session" : "$managedCount managed sessions";
		$confirmTitle = "Sign out $label from {$applicationCatalogue->selected()->name} · $scopeTitle?";
	}

	foreach($sessions as $entry) {
		$session = $entry->session;
		$item = $template->insertListItem();
		$binder->bindKeyValue("sessionId", $session->id, $item);
		$binder->bindKeyValue("sessionUrl", (string)$sessionUrl, $item);
		$binder->bindKeyValue("sessionEmail", $entry->email, $item);
		$binder->bindKeyValue("sessionEmailId", "session-" . $session->id . "-email", $item);
		$binder->bindKeyValue("sessionTitle", $entry->deploymentTitle, $item);
		$binder->bindKeyValue("sessionStarted", $session->createdAt->format("Y-m-d H:i:s"), $item);
		$binder->bindKeyValue("sessionLastActivity", $session->lastActivityAt?->format("Y-m-d H:i:s") ?? "Not available", $item);
		$binder->bindKeyValue("sessionExpires", $session->expiresAt->format("Y-m-d H:i:s"), $item);
		$binder->bindKeyValue("sessionType", $session->managed ? "Managed" : "Older client · estimate", $item);
		$binder->bindKeyValue("sessionCanRevoke", $session->managed, $item);

		if($confirm === $session->id && $session->managed) {
			$confirmTitle = "Sign out this session from {$entry->deploymentTitle}?";
		}
	}

	$binder->bindKeyValue("showSessionConfirmation", $confirmTitle !== null);
	$binder->bindKeyValue("sessionConfirmationTitle", $confirmTitle ?? "");
	$binder->bindKeyValue("confirmSession", is_string($confirm) ? $confirm : "");

}

function do_save_sessions(Input $input, AdminArea $area, ApplicationCatalogue $applications,
	ApplicationRepository $repository, FlashSession $flash, Response $response):void {
	$lifetime = $input->get("maximumLifetimeMinutes", Input::DATA_BODY);
	$idle = $input->get("idleTimeoutMinutes", Input::DATA_BODY);

	try {
		if(!is_string($lifetime) || !ctype_digit($lifetime) || !is_string($idle) || !ctype_digit($idle)) {
			throw new InvalidArgumentException("Enter whole minutes for both timeouts.");
		}

		$repository->saveSessionPolicy($area->administrator(),
			new ApplicationSessionPolicy($applications->selected()->identifier, (int)$lifetime, (int)$idle));
		$flash->setFlash("Session policy saved. Shorter limits also apply to existing managed sessions.", "session_result");
	}
	catch(InvalidArgumentException $error) {
		$flash->setFlash($error->getMessage(), "session_error");
	}

	$response->redirect((new Uri("/admin/security/"))
		->withQueryValue("application", $applications->selected()->identifier)->withFragment("sessions"));
}

function do_confirm_revoke(Input $input, Request $request, Response $response):void {
	$target = $input->get("sessionId", Input::DATA_BODY);

	if(!is_string($target)) {
		throw new HttpUnprocessableEntity();
	}

	$response->redirect($request->getUri()->withQueryValue("confirmSession", $target)->withFragment("sessions"));
}

function do_revoke_sessions(Input $input, AdminArea $area, ApplicationCatalogue $applications,
	UserRepository $users, Request $request, Response $response, FlashSession $flash):void {
	$target = $input->get("confirmRevoke", Input::DATA_BODY);
	$confirmation = $input->get("confirmSession", Input::DATA_QUERYSTRING);
	$deployment = $input->get("sessionDeployment", Input::DATA_QUERYSTRING) ?? "";

	if(!is_string($target) || $target !== $confirmation || !is_string($deployment)) {
		throw new HttpUnprocessableEntity();
	}

	try {
		$applicationId = $applications->selected()->identifier;

		if($target === "all") {
			$count = $users->revokeAllSessions($area->administrator(), $applicationId, $deployment);
			$label = $count === 1 ? "1 managed session" : "$count managed sessions";
			$flash->setFlash("$label revoked. Users must sign in again on their next request.", "session_result");
		}
		else {
			$users->revokeSession($area->administrator(), $applicationId, $target);
			$flash->setFlash("Session revoked. The user must sign in again on their next request.", "session_result");
		}
	}
	catch(InvalidArgumentException $error) {
		$flash->setFlash($error->getMessage(), "session_error");
	}

	$response->redirect($request->getUri()->withoutQueryValue("confirmSession")->withFragment("sessions"));
}
