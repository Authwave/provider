<?php
use Authwave\Admin\DemoWorkspace;
use Authwave\View\AdminView;
use Gt\Dom\Element;
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;

function go(Element $element, Binder $binder, Input $input, DemoWorkspace $workspace, Uri $uri):void {
	$view = new AdminView($element, $binder, $input, $workspace, $uri);
	$sessions = array_filter($workspace->users(), fn($user) => $user["status"] === "Abandoned");
	usort($sessions, fn($a, $b) => $b["signupStartedAt"] <=> $a["signupStartedAt"]);
	$view->list("abandoned-users", array_map(fn($session) => $view->signupRow($session, "signupStartedAt"), array_slice($sessions, 0, 5)));
	$view->keys(["hasAbandonedUsers" => count($sessions) > 0,
		"abandonedUsersUrl" => $workspace->url("users", ["userStatus" => "abandoned", "userSort" => "created"])]);
	$view->forms();
}
