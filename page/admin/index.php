<?php
use Authwave\Admin\Dashboard\{DashboardRepository, ReportingPeriod};
use Authwave\Admin\Application\ApplicationCatalogue;
use Authwave\Admin\Dashboard\DashboardSeriesPoint;
use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Model\ApplicationRepository;
use Authwave\Security\Action;
use GT\Dom\Element;
use GT\Dom\HTMLDocument;
use GT\DomTemplate\Binder;
use GT\DomTemplate\ListElementCollection;
use GT\Http\Request;
use GT\Http\ResponseStatusException\ClientError\HttpUnprocessableEntity;
use GT\Input\Input;

function go(DashboardRepository $dashboard, ApplicationCatalogue $applications,
	AdminArea $area, Binder $binder, Input $input, Request $request,
	ApplicationRepository $applicationRepository, HTMLDocument $document,
	ListElementCollection $lists):void {
	$periodName = $input->get("period", Input::DATA_QUERYSTRING) ?? "30d";
	$from = $input->get("from", Input::DATA_QUERYSTRING);
	$to = $input->get("to", Input::DATA_QUERYSTRING);
	$action = $input->get("activityAction", Input::DATA_QUERYSTRING) ?? "";
	$page = $input->get("activityPage", Input::DATA_QUERYSTRING) ?? "1";
	$comparison = $input->get("comparison", Input::DATA_QUERYSTRING) ?? "previous";

	if(!is_string($periodName) || ($from !== null && !is_string($from))
	|| ($to !== null && !is_string($to)) || !is_string($action)
	|| !is_string($page) || !ctype_digit($page)
	|| !in_array($comparison, ["previous", "none"], true)) {
		throw new HttpUnprocessableEntity();
	}

	$actor = $area->administrator();
	$applicationId = $applications->selected()->identifier;
	$deployments = $applicationRepository->deployments($actor, $applicationId);
	$productionId = $deployments[0]->application->productionApplicationDeploymentId ?? null;
	$deploymentId = $input->get("deployment", Input::DATA_QUERYSTRING) ?? $productionId ?? "";

	if(!is_string($deploymentId)
	|| ($deploymentId !== "" && !in_array($deploymentId, array_column($deployments, "id"), true))) {
		throw new HttpUnprocessableEntity();
	}

	$binder->bindListCallback($deployments,
		function(Element $element, array $fields)use($deploymentId):array {
			$fields["selected"] = $fields["id"] === $deploymentId;
			return $fields;
		}, templateName: "report-deployments");
	$binder->bindKeyValue("deployment", $deploymentId);
	$binder->bindKeyValue("allDeployments", $deploymentId === "");
	$deploymentLabel = "All deployments";

	foreach($deployments as $deployment) {
		if($deployment->id === $deploymentId) {
			$deploymentLabel = $deployment->title;
		}
	}

	$binder->bindKeyValue("deploymentLabel", $deploymentLabel);
	$deploymentFilter = $deploymentId === "" ? null : $deploymentId;

	try {
		$period = new ReportingPeriod($periodName, $from, $to);
		$activity = $dashboard->activity($actor, $applicationId, $period, $action, (int)$page, $deploymentFilter);
	}
	catch(InvalidArgumentException) {
		throw new HttpUnprocessableEntity();
	}

	$totals = $dashboard->totals($actor, $applicationId, $period->start, $period->end, $deploymentFilter);
	$previous = $dashboard->totals($actor, $applicationId, $period->previousStart, $period->start, $deploymentFilter);
	$attempts = $totals->logins + $totals->failures;
	$previousAttempts = $previous->logins + $previous->failures;
	$binder->bindKeyValue("successRatio", $attempts ? round(100 * $totals->logins / $attempts, 1) . "%" : "—");
	$binder->bindKeyValue("previousRatio", $previousAttempts ? round(100 * $previous->logins / $previousAttempts, 1) . "%" : "—");

	$binder->bindKeyValue("logins", number_format($totals->logins));
	$binder->bindKeyValue("failures", number_format($totals->failures));
	$binder->bindKeyValue("codes", number_format($totals->codes));
	$binder->bindKeyValue("passwords", number_format($totals->passwords));
	$binder->bindKeyValue("previousLogins", number_format($previous->logins));
	$binder->bindKeyValue("previousFailures", number_format($previous->failures));
	$binder->bindKeyValue("previousCodes", number_format($previous->codes));
	$binder->bindKeyValue("previousPasswords", number_format($previous->passwords));

	$binder->bindKeyValue("newUsers", number_format($dashboard->newUserCount($actor,
		$applicationId, $period->start, $period->end, $deploymentFilter)));
	$binder->bindKeyValue("previousNewUsers", number_format($dashboard->newUserCount($actor,
		$applicationId, $period->previousStart, $period->start, $deploymentFilter)));
	$binder->bindKeyValue("applicationId", $applicationId);
	$binder->bindKeyValue("period", $periodName);
	$binder->bindKeyValue("from", $period->start->format("Y-m-d"));
	$binder->bindKeyValue("to", $period->end->modify("-1 day")->format("Y-m-d"));
	$binder->bindKeyValue("today", gmdate("Y-m-d"));
	$binder->bindKeyValue("periodLabel", $period->start->format("j M Y") . " – " . $period->end->modify("-1 day")->format("j M Y") . " (UTC)");
	$binder->bindKeyValue("previousLabel", $period->previousStart->format("j M Y") . " – " . $period->start->modify("-1 day")->format("j M Y"));

	foreach(["30d", "7d", "24h"] as $preset) {
		$binder->bindKeyValue("period" . $preset, $periodName === $preset ? "true" : "false");
	}

	$series = $dashboard->series($actor, $applicationId, $period, $deploymentFilter);
	$previousPeriod = new ReportingPeriod("custom", $period->previousStart->format("Y-m-d"),
		$period->start->modify("-1 day")->format("Y-m-d"));
	$previousSeries = $dashboard->series($actor, $applicationId, $previousPeriod, $deploymentFilter);
	$binder->bindKeyValue("chartData", json_encode([
		"labels" => array_map(fn(DashboardSeriesPoint $point):string => $point->bucket, $series),
		"current" => array_map(fn(DashboardSeriesPoint $point):int => $point->logins, $series),
		"previous" => array_map(fn(DashboardSeriesPoint $point):int => $point->logins, $previousSeries),
		"comparison" => $comparison, "comparisonTitle" => "Previous period",
	], JSON_THROW_ON_ERROR));
	$binder->bindListCallback($series, function(Element $element, array $fields, int $index)use($previousSeries):array {
		$fields["previousLogins"] = $previousSeries[$index]->logins;
		return $fields;
	}, templateName: "chart-data");
	$binder->bindKeyValue("bucketLabel", $period->days === 1 ? "Hour (UTC)" : "Date (UTC)");
	$binder->bindKeyValue("chartCaption", $period->days === 1
		? "Hourly authentication totals (UTC)" : "Daily authentication totals (UTC)");
	$binder->bindKeyValue("noLogins", $attempts === 0);
	$binder->bindKeyValue("hasChartData", $totals->logins > 0
		|| ($comparison === "previous" && $previous->logins > 0));
	$binder->bindKeyValue("comparison", $comparison);
	$binder->bindKeyValue("reportTitle", match($periodName) {
		"24h" => "Today", "7d" => "Last 7 days", "30d" => "Last 30 days",
		"custom" => "Custom period",
	});

	$topUsers = $dashboard->topUsers($actor, $applicationId, $period, $deploymentFilter);
	$binder->bindKeyValue("empty-top-users", count($topUsers) === 0);
	$topUsersTemplate = $lists->get($document, "top-users");

	foreach($topUsers as $user) {
		$item = $topUsersTemplate->insertListItem();
		$binder->bindData($user, $item);
		$binder->bindKeyValue("emailId", "dashboard-top-users-" . $user->userId, $item);
		$binder->bindKeyValue("userUri", "/admin/users/?" . http_build_query([
			"application" => $applicationId, "user" => $user->userId,
		]), $item);
	}

	foreach(["new-users", "awaiting-users"] as $templateName) {
		$users = $templateName === "new-users"
			? $dashboard->newUsers($actor, $applicationId, $period, $deploymentFilter)
			: $dashboard->awaitingFirstLogin($actor, $applicationId, $period, $deploymentFilter);
		$binder->bindKeyValue("empty-" . $templateName, count($users) === 0);
		$template = $lists->get($document, $templateName);

		foreach($users as $user) {
			$item = $template->insertListItem();
			$binder->bindData($user, $item);
			$binder->bindKeyValue("createdAt", $user->createdAt->format("Y-m-d H:i:s"), $item);
			$binder->bindKeyValue("emailId", "dashboard-" . $templateName . "-" . $user->userId, $item);
			$binder->bindKeyValue("userUri", "/admin/users/?" . http_build_query([
				"application" => $applicationId, "user" => $user->userId,
			]), $item);
		}
	}

	$binder->bindKeyValue("empty-activity", count($activity) === 0);
	$activityTemplate = $lists->get($document, "activity");

	foreach($activity as $event) {
		$item = $activityTemplate->insertListItem();
		$binder->bindKeyValue("createdAt", $event->createdAt->format("Y-m-d H:i:s"), $item);
		$binder->bindKeyValue("emailId", "dashboard-activity-" . $event->id, $item);
		$binder->bindKeyValue("email", $event->email, $item);
		$binder->bindKeyValue("userUri", "/admin/users/?" . http_build_query([
			"application" => $applicationId, "user" => $event->userId,
		]), $item);
		$binder->bindKeyValue("title", $event->deploymentTitle, $item);
		$binder->bindKeyValue("deploymentClass", $event->deploymentId === $productionId ? "badge production-badge" : "", $item);
		$binder->bindKeyValue("statusKind", match($event->action) {
			Action::LOGIN_COMPLETED => "success",
			Action::PASSWORD_LOGIN_FAILED, Action::SECURITY_CODE_REJECTED => "failed",
			default => "info",
		}, $item);
		$binder->bindKeyValue("status", match($event->action) {
			Action::LOGIN_COMPLETED => "Logged in",
			Action::PASSWORD_LOGIN_FAILED => "Password rejected",
			Action::SECURITY_CODE_REJECTED => "Security code rejected",
			Action::SECURITY_CODE_REQUESTED => "Security code requested",
			Action::PASSWORD_CHANGED => "Password changed",
			Action::LOGOUT_REQUESTED => "Logout requested",
			default => throw new LogicException("Unsupported dashboard event."),
		}, $item);
	}

	$binder->bindKeyValue("usersUri", "/admin/users/?application=" . rawurlencode($applicationId));
	$binder->bindKeyValue("newUsersUri", "/admin/users/?userSort=created&application=" . rawurlencode($applicationId));
	$binder->bindKeyValue("activityAction", $action);
	$binder->bindKeyValue("activityPage", $page);
	$binder->bindKeyValue("hasPrevious", $activity->hasPrevious());
	$binder->bindKeyValue("hasNext", $activity->hasNext);
	$uri = $request->getUri()->withQueryValue("application", $applicationId)
		->withQueryValue("deployment", $deploymentId)->withFragment("activity");
	$binder->bindKeyValue("previousPage", (string)$uri->withQueryValue("activityPage", (string)max(1, (int)$page - 1)));
	$binder->bindKeyValue("nextPage", (string)$uri->withQueryValue("activityPage", (string)((int)$page + 1)));
}
