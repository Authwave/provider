<?php
use Authwave\Admin\DemoWorkspace;
use Authwave\View\AdminView;
use Gt\Dom\Element;
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;

function go(Element $element, Binder $binder, Input $input, DemoWorkspace $workspace, Uri $uri):void {
	$view = new AdminView($element, $binder, $input, $workspace, $uri);
	$titles = ["index" => "Dashboard", "organisation" => "Organisation", "security" => "Security", "emails" => "Emails", "applications" => "Applications", "customisation" => "Customisation", "users" => "Users", "integrations" => "Integrations", "billing" => "Billing", "logout" => "Log out"];
	$apps = $workspace->applications();
	$scope = $workspace->applicationId === "" ? "No applications" : reset($apps)["name"];
	if($workspace->deploymentId !== "all") $scope .= " · " . reset($apps)["deployments"][$workspace->deploymentId]["name"];
	$notice = $workspace->notice();
	$view->keys(["pageTitle" => $titles[$view->page], "scopeLabel" => $workspace->organisation()["name"] . " · " . $scope, "notice" => $notice, "hasNotice" => $notice !== ""]);
	$view->forms();
}
