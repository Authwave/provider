<?php
use Authwave\Admin\Application\ApplicationCatalogue;
use Authwave\Admin\Dashboard\{DashboardRepository, ReportingPeriod};
use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Model\ApplicationRepository;
use Authwave\Security\Action;
use GT\Dom\Element;
use GT\Dom\HTMLDocument;
use GT\DomTemplate\Binder;
use GT\DomTemplate\ListElementCollection;
use GT\Http\ResponseStatusException\ClientError\HttpUnprocessableEntity;
use GT\Input\Input;

function go(
	Binder $binder,
	HTMLDocument $document,
	ListElementCollection $lists,
	Input $input,
	DashboardRepository $dashboardRepository,
	ApplicationCatalogue $applicationCatalogue,
	ApplicationRepository $applicationRepository,
	AdminArea $adminArea,
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

	$binder->bindKeyValue("from", $from);
	$binder->bindKeyValue("to", $to);
	$binder->bindKeyValue("event", $eventFilter);
	$binder->bindKeyValue("eventPage", $activity->page);
	$binder->bindKeyValue("applicationId", $applicationId);
	$binder->bindKeyValue("today", gmdate("Y-m-d"));
	$binder->bindKeyValue("allDeployments", $deploymentId === "");
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
	$query["eventPage"] = max(1, $page - 1);
	$binder->bindKeyValue("previousEvents", "/admin/security/?" . http_build_query($query) . "#security-events");
	$query["eventPage"] = $page + 1;
	$binder->bindKeyValue("nextEvents", "/admin/security/?" . http_build_query($query) . "#security-events");
	$binder->bindKeyValue("resetEvents", "/admin/security/?application=" . rawurlencode($applicationId) . "#security-events");
}
