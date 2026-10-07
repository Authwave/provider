<?php
use Authwave\Admin\DemoWorkspace;
use Authwave\View\AdminView;
use Gt\Dom\Element;
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;
use Authwave\Session\LoginSession;
use Authwave\Model\ApplicationRepository;
use Gt\Http\Response;

function go_before(LoginSession $login, ApplicationRepository $applications, Uri $uri, Response $response):void {
	// Components run before page/_common.php can establish the deployment for a fresh session.
	if($login->getDeployment()) return;
	$host = $uri->getHost();
	if($port = $uri->getPort()) {
		if($port !== 443) $host .= ":$port";
	}
	$login->setDeploymentForLogin($applications->getDeploymentByProviderHost($host));
	$login->requestAdmin();
	$response->redirect("/login/");
}

function go(Element $element, Binder $binder, Input $input, DemoWorkspace $workspace, Uri $uri):void {
	$view = new AdminView($element, $binder, $input, $workspace, $uri);
	$pages = ["dashboard" => "", "security" => "security", "emails" => "emails", "applications" => "applications", "customisation" => "customisation", "users" => "users", "integrations" => "integrations", "billing" => "billing", "logout" => "logout", "organisation" => "organisation"];
	foreach($pages as $key => $page) $binder->bindKeyValue($key . "Url", $workspace->url($page));
	foreach($element->querySelectorAll("[data-nav]") as $link) {
		$link->removeAttribute("aria-current");
		if($link->getAttribute("data-nav") === ($view->page === "index" ? "dashboard" : $view->page)) $link->setAttribute("aria-current", "page");
	}
	$rows = $workspace->setupSteps();
	$count = count(array_filter($rows, fn($row) => $row["stepComplete"]));
	$nextStep = current(array_filter($rows, fn($row) => !$row["stepComplete"]));
	$view->keys(["setupCount" => $count, "setupProgress" => "$count of 7 complete", "setupComplete" => $count === 7, "setupUrl" => $nextStep ? $nextStep["stepUrl"] : $workspace->url("applications")]);
	$view->list("sidebar-setup-steps", $rows);
	$view->forms();
}
