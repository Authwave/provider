<?php
use Authwave\Admin\DemoWorkspace;
use Authwave\View\AdminView;
use Gt\Dom\Element;
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;

function go(Element $element, Binder $binder, Input $input, DemoWorkspace $workspace, Uri $uri):void {
	$view = new AdminView($element, $binder, $input, $workspace, $uri);
	$users = array_filter($workspace->users(), fn($user) => $user["status"] !== "Abandoned");
	usort($users, fn($a, $b) => ($b["createdAt"] ?? $b["created"]) <=> ($a["createdAt"] ?? $a["created"]));
	$view->list("new-users", array_map($view->signupRow(...), array_slice($users, 0, 5)));
	$view->keys(["hasNewUsers" => count($users) > 0, "newUsersUrl" => $workspace->url("users", ["userSort" => "created"])]);
	$view->forms();
}
