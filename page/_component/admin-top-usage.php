<?php
use Authwave\Admin\DemoWorkspace;
use Authwave\View\AdminView;
use Authwave\UI\EmailAvatar;
use Gt\Dom\Element;
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;

function go(Element $element, Binder $binder, Input $input, DemoWorkspace $workspace, Uri $uri):void {
	$view = new AdminView($element, $binder, $input, $workspace, $uri);
	$group = $view->choice("topUsage", ["users", "countries", "devices"], "users");
	$users = array_filter($workspace->users(), fn($user) => $user["status"] !== "Abandoned");
	$counts = [];
	foreach($users as $user) {
		$key = match($group) { "countries" => $user["country"], "devices" => $user["device"], default => $user["email"] };
		$counts[$key] = ($counts[$key] ?? 0) + $user["logins"];
	}
	arsort($counts);
	$rows = [];
	foreach(array_slice($counts, 0, 5, true) as $name => $count) {
		$rows[] = ["usageName" => $group === "users" ? DemoWorkspace::email($name, $view->reveal) : $name, "usageAvatar" => $group === "users" ? EmailAvatar::svg($name) : "", "hasUsageAvatar" => $group === "users", "usageCount" => number_format($count)];
	}
	$view->keys(["topUsage" => $group, "hasTopUsage" => count($rows) > 0,
		"usageGroupTitle" => match($group) { "countries" => "Country", "devices" => "Device", default => "User" },
		"topUsersUrl" => $workspace->url("users", ["userSort" => "logins"])]);
	$view->list("top-usage", $rows);
	foreach($element->querySelectorAll('button[name="topUsage"]') as $button) {
		$button->setAttribute("aria-pressed", $button->value === $group ? "true" : "false");
	}
	$view->forms();
}
