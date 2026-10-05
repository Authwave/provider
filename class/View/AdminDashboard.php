<?php
namespace Authwave\View;

use Authwave\Session\LoginSession;
use DateTimeImmutable;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\Binder;
use Gt\Input\Input;

/** Sample reporting data for the first dashboard; no authentication records are queried. */
class AdminDashboard {
	public function apply(HTMLDocument $document, Binder $binder, LoginSession $login, Input $input):void {
		$deployment = $login->getDeployment();
		(new LoginBranding())->bindIdentity($binder, $deployment);
		$state = [
			"period" => $this->choice($input, "period", ["24h", "7d", "30d", "12m"], "12m"),
			"status" => $this->choice($input, "status", ["all", "success", "failed", "abandoned"], "all"),
			"method" => $this->choice($input, "method", ["all", "password", "email"], "all"),
			"activity" => $this->choice($input, "activity", ["all", "abandoned", "top"], "all"),
			"sort" => $this->choice($input, "sort", ["date", "user", "status"], "date"),
			"search" => substr(trim($input->getString("search") ?? ""), 0, 100),
			"from" => $this->date($input->getString("from")),
			"to" => $this->date($input->getString("to")),
		];
		if($state["from"] && $state["to"] && $state["from"] > $state["to"]) {
			[$state["from"], $state["to"]] = [$state["to"], $state["from"]];
		}
		$today = new DateTimeImmutable("today");
		$span = ["24h" => 1, "7d" => 7, "30d" => 30, "12m" => 365][$state["period"]];
		$from = $state["from"] ? new DateTimeImmutable($state["from"]) : $today->modify("-" . ($span - 1) . " days");
		$to = $state["to"] ? new DateTimeImmutable($state["to"]) : $today;
		$labels = $current = $previous = $points = [];
		$factor = ($state["method"] === "all" ? 1 : 0.65) * ($state["status"] === "all" ? 1 : 0.4);
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
		$rows = array_values(array_filter($rows, static function(array $row) use($state, $from, $to):bool {
			return $row["dateValue"] >= $from->format("Y-m-d") && $row["dateValue"] <= $to->format("Y-m-d")
				&& ($state["status"] === "all" || $row["statusValue"] === $state["status"])
				&& ($state["method"] === "all" || $row["methodValue"] === $state["method"])
				&& ($state["activity"] !== "abandoned" || $row["statusValue"] === "abandoned")
				&& ($state["activity"] !== "top" || $row["statusValue"] === "success")
				&& (!$state["search"] || str_contains(strtolower($row["authId"] . " " . $row["userName"] . " " . $row["userEmail"]), strtolower($state["search"])));
		}));
		if($state["activity"] === "top") {
			usort($rows, static fn($a, $b) => $b["loginCount"] <=> $a["loginCount"]);
		}
		elseif($state["sort"] !== "date") {
			$key = $state["sort"] === "user" ? "userName" : "statusValue";
			usort($rows, static fn($a, $b) => $a[$key] <=> $b[$key]);
		}
		$count = count($rows);
		$pageCount = max(1, (int)ceil($count / 7));
		$page = min($pageCount, max(1, (int)$input->getString("page")));
		foreach($state as $key => $value) {
			$binder->bindKeyValue($key, $value);
		}
		foreach([
			"applicationId" => $deployment->application->id,
			"title" => "$deployment->title - Administration",
			"accountEmail" => $login->getEmail(),
			"accountInitial" => strtoupper(substr($login->getEmail() ?? "A", 0, 1)),
			"clientUri" => (string)$deployment->getClientReturnUri()->withPath("/"),
			"periodTitle" => ["24h" => "Today", "7d" => "This week", "30d" => "This month", "12m" => "This year"][$state["period"]],
			"dateLabel" => $from->format("j M Y") . " – " . $to->format("j M Y"),
			"loginTotal" => number_format(array_sum($current)),
			"userTotal" => number_format((int)round(array_sum($current) / 35)),
			"activityTotal" => $count,
			"paginationLabel" => "Page $page of $pageCount",
			"previousPage" => "/admin/?" . http_build_query($state + ["page" => max(1, $page - 1)]) . "#activity",
			"nextPage" => "/admin/?" . http_build_query($state + ["page" => min($pageCount, $page + 1)]) . "#activity",
			"chartData" => json_encode(["labels" => $labels, "current" => $current, "previous" => $previous], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_THROW_ON_ERROR),
			"hasActivities" => $count > 0,
			"hasPrevious" => $page > 1,
			"hasNext" => $page < $pageCount,
		] as $key => $value) {
			$binder->bindKeyValue($key, $value);
		}
		$binder->bindList(array_slice($rows, ($page - 1) * 7, 7), templateName: "activity");
		$binder->bindList($points, templateName: "chart-point");
		foreach($document->querySelectorAll("button[data-choice]") as $button) {
			$key = $button->getAttribute("name");
			$button->setAttribute("aria-pressed", ($state[$key] ?? null) === $button->getAttribute("value") ? "true" : "false");
		}
		foreach($document->querySelectorAll("select[data-state]") as $select) {
			foreach($select->querySelectorAll("option") as $option) {
				$option->toggleAttribute("selected", $option->getAttribute("value") === $state[$select->getAttribute("name")]);
			}
		}
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
		$people = [
			["Sienna Hewitt", "sienna@example.com", "SH"], ["Ammar Foley", "ammar@example.com", "AF"],
			["Pippa Wilkinson", "pippa@example.com", "PW"], ["Olly Schroeder", "olly@example.com", "OS"],
			["Mathilde Lewis", "mathilde@example.com", "ML"], ["Julius Vaughan", "julius@example.com", "JV"],
			["Zaid Schwartz", "zaid@example.com", "ZS"],
		];
		$rows = [];
		for($i = 0; $i < 28; $i++) {
			[$name, $email, $initials] = $people[$i % 7];
			$status = ["success", "success", "success", "failed", "success", "abandoned", "abandoned"][$i % 7];
			$date = $today->modify("-" . (int)floor($i / 3) . " days");
			$rows[] = ["authId" => "#" . (26678 - $i), "activityDate" => $date->format("j M Y"), "dateValue" => $date->format("Y-m-d"),
				"statusValue" => $status, "statusLabel" => ucfirst($status), "userName" => $name, "userEmail" => $email,
				"userInitials" => $initials, "methodValue" => $i % 2 ? "password" : "email", "loginCount" => 140 - $i];
		}
		return $rows;
	}
}
