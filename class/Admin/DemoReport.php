<?php
namespace Authwave\Admin;

use DateTimeImmutable;
use Authwave\UI\EmailAvatar;
use Gt\Input\Input;

/** Normalised report filters and sample data, shared by the dashboard components. */
class DemoReport {
	public array $state;
	public array $values;
	public array $rows;
	public array $points;
	public function __construct(Input $input, DemoWorkspace $workspace) {
		$comparisonOptions = ["previous", "previous-1", "month", "quarter", "year", "none"];
		$state = [
			"reportComparison" => $this->choice($input, "reportComparison", $comparisonOptions, $this->choice($input, "comparison", $comparisonOptions, "previous")),
			"period" => $this->choice($input, "period", ["24h", "7d", "30d", "12m"], "30d"),
			"status" => $this->choice($input, "status", ["all", "success", "failed", "abandoned"], "all"),
			"method" => $this->choice($input, "method", ["all", "password", "email"], "all"),
			"activity" => $this->choice($input, "activity", ["all", "abandoned", "top"], "all"),
			"sort" => $this->choice($input, "sort", ["date", "user", "status"], "date"),
			"search" => substr(trim($input->getString("search") ?? ""), 0, 100),
			"from" => $this->date($input->getString("from")),
			"to" => $this->date($input->getString("to")),
		];
		$activityFields = ["success" => "activitySuccess", "failed" => "activityFailed", "abandoned" => "activityAbandoned"];
		foreach($activityFields as $status => $key) {
			$default = $state["activity"] === "abandoned" ? $status === "abandoned" : ($state["activity"] === "top" ? $status === "success" : true);
			$state[$key] = $this->choice($input, $key, ["yes", "no"], $default ? "yes" : "no");
		}
		if($state["from"] && $state["to"] && $state["from"] > $state["to"]) {
			[$state["from"], $state["to"]] = [$state["to"], $state["from"]];
		}
		$today = new DateTimeImmutable("today");
		$span = ["24h" => 1, "7d" => 7, "30d" => 30, "12m" => 365][$state["period"]];
		$from = $state["from"] ? new DateTimeImmutable($state["from"]) : $today->modify("-" . ($span - 1) . " days");
		$to = $state["to"] ? new DateTimeImmutable($state["to"]) : $today;
		$labels = $current = $previous = $points = [];
		$factor = ($state["method"] === "all" ? 1 : 0.65) * ($state["status"] === "all" ? 1 : 0.4) * ($workspace ? count($workspace->applications()) : 1);
		for($i = 0; $i < 12; $i++) {
			$date = $state["period"] === "24h" && !$state["from"] && !$state["to"]
				? $today->modify("+" . ($i * 2) . " hours")
				: $from->modify("+" . (int)round(($to->getTimestamp() - $from->getTimestamp()) / 86400 * $i / 11) . " days");
			$labels[] = $date->format($state["period"] === "24h" ? "H:i" : ($state["period"] === "12m" ? "M" : "j M"));
			$current[] = (int)round((280 + $i * 22 + [0, 18, -9, 12][$i % 4]) * $factor);
			$previous[] = (int)round($current[$i] / 1.074);
			$points[] = ["pointLabel" => $labels[$i], "pointCurrent" => $current[$i], "pointPrevious" => $previous[$i]];
		}
		$rows = $this->activities($today);
		if($workspace && !$workspace->applications()) $rows = [];
		$comparison = $state["reportComparison"];
		$comparisonTitle = ["previous" => "Last period", "previous-1" => "Last period - 1", "month" => "Same point last month", "quarter" => "Previous quarter", "year" => "Previous year", "none" => "No comparison"][$comparison];
		$comparisonFactor = ["previous" => 1.074, "previous-1" => 1.14, "month" => 1.1, "quarter" => 1.19, "year" => 1.3, "none" => 1.074][$comparison];
		$previous = array_map(static fn($value) => (int)round($value / $comparisonFactor), $current);
		foreach($points as $i => &$point) $point["pointPrevious"] = $previous[$i];
		unset($point);
		$rows = array_values(array_filter($rows, static function(array $row) use($state, $from, $to, $activityFields):bool {
			return $row["dateValue"] >= $from->format("Y-m-d") && $row["dateValue"] <= $to->format("Y-m-d")
				&& $state[$activityFields[$row["statusValue"]]] === "yes"
				&& ($state["status"] === "all" || $row["statusValue"] === $state["status"])
				&& ($state["method"] === "all" || $row["methodValue"] === $state["method"])
				&& ($state["activity"] !== "abandoned" || $row["statusValue"] === "abandoned")
				&& ($state["activity"] !== "top" || $row["statusValue"] === "success")
				&& (!$state["search"] || str_contains(strtolower($row["authId"] . " " . $row["userEmail"]), strtolower($state["search"])));
		}));
		if($state["activity"] === "top") {
			usort($rows, static fn($a, $b) => $b["loginCount"] <=> $a["loginCount"]);
		}
		elseif($state["sort"] !== "date") {
			$key = $state["sort"] === "user" ? "userEmail" : "statusValue";
			usort($rows, static fn($a, $b) => $a[$key] <=> $b[$key]);
		}
		if($workspace && $input->getString("reveal") !== "yes") foreach($rows as &$row) $row["userEmail"] = DemoWorkspace::email($row["userEmail"]);
		unset($row);
		$count = count($rows);
		$pageCount = max(1, (int)ceil($count / 7));
		$page = min($pageCount, max(1, (int)$input->getString("page")));
		$this->state = $state;
		$this->values = [
			"periodText" => $state["from"] || $state["to"]
				? "between " . $from->format("j M Y") . " and " . $to->format("j M Y")
				: ["24h" => "today", "7d" => "for the last 7 days", "30d" => "for the last 30 days", "12m" => "for the last 12 months"][$state["period"]],
			"comparisonTitle" => $comparisonTitle,
			"dateLabel" => $from->format("j M Y") . " – " . $to->format("j M Y"),
			"usageTotal" => number_format(array_sum($current)),
			"userTotal" => number_format((int)round(array_sum($current) / 35)),
			"securityCodeTotal" => number_format((int)round(array_sum($current) * .52)),
			"passwordChangeTotal" => number_format((int)round(array_sum($current) * .018)),
			"successfulLoginRatio" => array_sum($current) > 0 ? "96%" : "0%",
			"providerLoginTotal" => number_format((int)round(array_sum($current) * .28)),
			"paginationLabel" => "Page $page of $pageCount",
			"previousPage" => "/admin/?" . http_build_query($state + ["page" => max(1, $page - 1)]) . "#activity",
			"nextPage" => "/admin/?" . http_build_query($state + ["page" => min($pageCount, $page + 1)]) . "#activity",
			"chartData" => json_encode(["labels" => $labels, "current" => $current, "previous" => $previous, "comparison" => $comparison, "comparisonTitle" => $comparisonTitle], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_THROW_ON_ERROR),
			"hasActivities" => $count > 0,
			"hasPrevious" => $page > 1,
			"hasNext" => $page < $pageCount,
		];
		$this->rows = array_slice($rows, ($page - 1) * 7, 7);
		$this->points = $points;
	}

	private function choice(Input $input, string $key, array $choices, string $default):string {
		$value = $input->getString($key);
		return in_array($value, $choices, true) ? $value : $default;
	}

	private function date(?string $value):string {
		$date = DateTimeImmutable::createFromFormat("!Y-m-d", $value ?? "");
		return $date && $date->format("Y-m-d") === $value ? $value : "";
	}

	private function activities(DateTimeImmutable $today):array {
		$people = ["sienna@example.test", "ammar@example.test", "pippa@example.test", "olly@example.test", "mathilde@example.test", "julius@example.test", "zaid@example.test"];
		$rows = [];
		for($i = 0; $i < 28; $i++) {
			$email = $people[$i % 7];
			$status = ["success", "success", "success", "failed", "success", "abandoned", "abandoned"][$i % 7];
			$date = $today->modify("-" . (int)floor($i / 3) . " days");
			$rows[] = ["authId" => "#" . (26678 - $i), "activityDate" => $date->format("j M Y"), "dateValue" => $date->format("Y-m-d"),
				"statusValue" => $status, "statusLabel" => ucfirst($status), "userEmail" => $email,
				"userAvatar" => EmailAvatar::svg($email), "methodValue" => $i % 2 ? "password" : "email", "loginCount" => 140 - $i];
		}
		return $rows;
	}
}
