<?php
use Authwave\Admin\DemoWorkspace;
use Authwave\View\AdminView;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;

function go(HTMLDocument $document, Binder $binder, Input $input, DemoWorkspace $workspace, Uri $uri):void {
	$view = new AdminView($document, $binder, $input, $workspace, $uri);
	$view->bindSettings($workspace->settings());
	$users = $workspace->users();
	$online = $workspace->settings()["sessionsRevoked"] === "yes" ? [] : array_values(array_filter($users, fn($user) => $user["online"]));
	$view->list("online-users", array_map($view->userRow(...), $online));
	$view->list("failed-users", array_map($view->userRow(...), array_values(array_filter($users, fn($user) => $user["status"] === "Failed"))));
	$search = substr(trim($input->getString("search") ?? ""), 0, 100);
	$status = $view->choice("userStatus", ["all", "online", "failed", "abandoned"], "all");
	$sort = $view->choice("userSort", ["logins", "created", "email"], "logins");
	$users = array_values(array_filter($users, fn($user) => (!$search || str_contains(strtolower($user["email"]), strtolower($search))) && match($status) {"online" => $user["online"] && $workspace->settings()["sessionsRevoked"] !== "yes", "failed" => $user["status"] === "Failed", "abandoned" => $user["status"] === "Abandoned", default => true}));
	usort($users, fn($a, $b) => $sort === "email" ? $a["email"] <=> $b["email"] : ($sort === "created" ? ($b["createdAt"] ?? $b["created"]) <=> ($a["createdAt"] ?? $a["created"]) : $b[$sort] <=> $a[$sort]));
	$pages = max(1, (int)ceil(count($users) / 10));
	$page = min($pages, max(1, (int)$input->getString("page")));
	$query = ["search" => $search, "userStatus" => $status, "userSort" => $sort, "reveal" => $view->reveal ? "yes" : ""];
	$view->keys(["onlineCount" => count($online), "search" => $search, "userStatus" => $status, "userSort" => $sort, "hasUsers" => count($users) > 0, "userPagination" => "Page $page of $pages · " . count($users) . " users", "hasUserPrevious" => $page > 1, "hasUserNext" => $page < $pages, "userPrevious" => $workspace->url("users", ["page" => max(1, $page - 1)] + $query), "userNext" => $workspace->url("users", ["page" => min($pages, $page + 1)] + $query)]);
	$view->list("users", array_map($view->userRow(...), array_slice($users, ($page - 1) * 10, 10)));
	$view->forms();
}
