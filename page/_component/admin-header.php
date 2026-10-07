<?php
use Authwave\Admin\DemoWorkspace;
use Authwave\View\AdminView;
use Gt\Dom\Element;
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;
use Authwave\Session\LoginSession;

function go(Element $element, Binder $binder, Input $input, DemoWorkspace $workspace, Uri $uri, LoginSession $login):void {
	$view = new AdminView($element, $binder, $input, $workspace, $uri);
	$view->keys([
		"applicationName" => $login->getDeployment()->title,
		"clientUri" => (string)$login->getDeployment()->getClientReturnUri()->withPath("/"),
		"headerSearch" => $input->getString("search") ?? "",
		"addUserUrl" => $workspace->url("users") . "#add-user", "securityUrl" => $workspace->url("security"),
	]);
	$csv = fopen("php://temp", "r+");
	fputcsv($csv, ["Email", "Application", "Log-ins", "Created"], ",", '"', "");
	foreach($workspace->users() as $user) {
		$values = [DemoWorkspace::email($user["email"]), $user["application"], (string)$user["logins"], $user["created"]];
		fputcsv($csv, array_map(static fn($value) => preg_match('/^[=+@-]/', $value) ? "'" . $value : $value, $values), ",", '"', "");
	}
	rewind($csv);
	$binder->bindKeyValue("exportUrl", "data:text/csv;charset=utf-8," . rawurlencode(stream_get_contents($csv)));
	fclose($csv);
	$view->forms();
}
