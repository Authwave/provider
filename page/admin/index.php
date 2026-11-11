<?php
use Authwave\Admin\Dashboard\{DashboardRepository, ReportingPeriod};
use Authwave\Admin\Application\ApplicationCatalogue;
use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Model\ApplicationRepository;
use GT\Dom\Element;
use GT\DomTemplate\Binder;
use GT\Http\Request;
use GT\Http\ResponseStatusException\ClientError\HttpUnprocessableEntity;
use GT\Input\Input;

function go(DashboardRepository $dashboard, ApplicationCatalogue $applications,
	AdminArea $area, Binder $binder, Input $input, Request $request,
	ApplicationRepository $applicationRepository):void {
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
	$attempts = $totals->getInt("logins") + $totals->getInt("failures");
	$previousAttempts = $previous->getInt("logins") + $previous->getInt("failures");
	$binder->bindKeyValue("successRatio", $attempts ? round(100 * $totals->getInt("logins") / $attempts, 1) . "%" : "—");
	$binder->bindKeyValue("previousRatio", $previousAttempts ? round(100 * $previous->getInt("logins") / $previousAttempts, 1) . "%" : "—");

	foreach(["logins", "failures", "codes", "passwords"] as $metric) {
		$binder->bindKeyValue($metric, number_format($totals->getInt($metric)));
		$binder->bindKeyValue("previous" . ucfirst($metric), number_format($previous->getInt($metric)));
	}

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
		"labels" => array_map(fn($row) => $row->getString("bucket"), $series),
		"current" => array_map(fn($row) => $row->getInt("logins"), $series),
		"previous" => array_map(fn($row) => $row->getInt("logins"), $previousSeries),
		"comparison" => $comparison, "comparisonTitle" => "Previous period",
	], JSON_THROW_ON_ERROR));
	$binder->bindListCallback($series, function(Element $element, array $fields, int $index)use($previousSeries):array {
		$fields["previousLogins"] = $previousSeries[$index]->getInt("logins");
		return $fields;
	}, templateName: "chart-data");
	$binder->bindKeyValue("bucketLabel", $period->days === 1 ? "Hour (UTC)" : "Date (UTC)");
	$binder->bindKeyValue("chartCaption", $period->days === 1
		? "Hourly authentication totals (UTC)" : "Daily authentication totals (UTC)");
	$binder->bindKeyValue("noLogins", $attempts === 0);
	$binder->bindKeyValue("hasChartData", $totals->getInt("logins") > 0
		|| ($comparison === "previous" && $previous->getInt("logins") > 0));
	$binder->bindKeyValue("comparison", $comparison);
	$binder->bindKeyValue("reportTitle", match($periodName) {
		"24h" => "Today", "7d" => "Last 7 days", "30d" => "Last 30 days",
		"custom" => "Custom period",
	});

	foreach(["top-users" => $dashboard->topUsers($actor, $applicationId, $period, $deploymentFilter),
		"new-users" => $dashboard->newUsers($actor, $applicationId, $period, $deploymentFilter),
		"awaiting-users" => $dashboard->awaitingFirstLogin($actor, $applicationId, $period, $deploymentFilter),
		"activity" => array_slice($activity, 0, DashboardRepository::PAGE_SIZE)] as $template => $rows) {
		$binder->bindKeyValue("empty-" . $template, count($rows) === 0);
		$binder->bindListCallback($rows, function(Element $element, array $fields)use($applicationId, $template, $productionId):array {
			$fields["emailId"] = "dashboard-" . $template . "-" . ($fields["id"] ?? $fields["userId"]);
			$fields["userUri"] = "/admin/users/?" . http_build_query([
				"application" => $applicationId, "user" => $fields["userId"],
			]);

			if(isset($fields["action"])) {
				$fields["deploymentClass"] = $fields["deploymentId"] === $productionId
					? "badge production-badge" : "";
				$fields["statusKind"] = match($fields["action"]) {
					"LOGIN_COMPLETED" => "success",
					"PASSWORD_LOGIN_FAILED", "SECURITY_CODE_REJECTED" => "failed",
					default => "info",
				};
				$fields["status"] = match($fields["action"]) {
					"LOGIN_COMPLETED" => "Logged in",
					"PASSWORD_LOGIN_FAILED" => "Password rejected",
					"SECURITY_CODE_REJECTED" => "Security code rejected",
					"SECURITY_CODE_REQUESTED" => "Security code requested",
					"PASSWORD_CHANGED" => "Password changed",
					"LOGOUT_REQUESTED" => "Logout requested",
				};
			}

			return $fields;
		}, templateName: $template);
	}

	$binder->bindKeyValue("usersUri", "/admin/users/?application=" . rawurlencode($applicationId));
	$binder->bindKeyValue("newUsersUri", "/admin/users/?userSort=created&application=" . rawurlencode($applicationId));
	$binder->bindKeyValue("activityAction", $action);
	$binder->bindKeyValue("activityPage", $page);
	$binder->bindKeyValue("hasPrevious", (int)$page > 1);
	$binder->bindKeyValue("hasNext", count($activity) > DashboardRepository::PAGE_SIZE);
	$uri = $request->getUri()->withQueryValue("application", $applicationId)
		->withQueryValue("deployment", $deploymentId)->withFragment("activity");
	$binder->bindKeyValue("previousPage", (string)$uri->withQueryValue("activityPage", (string)max(1, (int)$page - 1)));
	$binder->bindKeyValue("nextPage", (string)$uri->withQueryValue("activityPage", (string)((int)$page + 1)));
}
