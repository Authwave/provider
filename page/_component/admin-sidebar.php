<?php
use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Admin\Onboarding\Onboarding;
use GT\Config\Config;
use GT\Http\Request;
use GT\Session\Session;
use GT\Cookie\CookieHandler;
use GT\Dom\Element;
use GT\DomTemplate\Binder;
use GT\Http\Uri;
use GT\Http\Response;
use Authwave\Security\Sudo;
use GT\Input\Input;

function go_before(
	AdminArea $area,
	Uri $uri,
	Response $response,
):void {
	$area->enter($uri, $response);
}

function go(Element $element, Binder $binder, Uri $uri, Onboarding $onboarding,
	Sudo $sudo, AdminArea $area, Input $input, Request $request):void {
	if(is_string($input->get("verify", Input::DATA_QUERYSTRING))) {
		$progress = $element->querySelector(".setup-card");
		$progress->setAttribute("data-flux", "live");
		$progress->setAttribute("data-flux-rate", "10");
	}

	// Components render before page logic: renew before binding the deadline.
	$actor = $area->administrator();
	$until = $request->getHeaderLine("X-Authwave-Background") === "1"
		? $sudo->expiresAt($actor) : $sudo->renew($actor);
	$binder->bindKeyValue("sudoUntil", $until);
	$return = $uri->getPath() . ($uri->getQuery() ? "?" . $uri->getQuery() : "");
	// This form submits to /sudo/ page logic, rather than a sidebar action.
	$element->querySelector('[data-sudo-disable] input[name="__component"]')->remove();
	$element->querySelector("[data-sudo-disable]")->setAttribute("action",
		"/sudo/?" . http_build_query(["return" => $return]));

	$binder->bindKeyValue("needsApplication", $onboarding->needsApplication());
	$binder->bindKeyValue("completedCount", $onboarding->completedCount());
	$binder->bindList($onboarding->steps(), templateName: "setup-steps");
	$path = rtrim($uri->getPath(), "/");
	$scopePath = $path . "/";

	if(str_starts_with($path, "/admin/applications/")) {
		$scopePath = "/admin/applications/";
	}

	$element->querySelector("[data-scope-form]")->setAttribute("action", $scopePath);

	if($path === "/integrations/help") {
		$path = "/admin/integrations";
	}

	foreach($element->querySelectorAll("[data-nav][href]") as $link) {
		$target = rtrim($link->getAttribute("href"), "/");
		$link->removeAttribute("aria-current");

		if($path === $target || ($target !== "/admin" && str_starts_with($path, $target . "/"))) {
			$link->setAttribute("aria-current", "page");
		}
	}
}

function do_logout(
	AdminArea $area,
	Request $request,
	Response $response,
	Session $session,
	Config $config,
	CookieHandler $cookies,
):void {
	if($request->getMethod() !== "POST"
	|| rtrim($request->getUri()->getPath(), "/") !== "/admin/logout") {
		return;
	}

	$area->logout($session, $cookies);
	$response->redirect($config->getString("authwave.logout_redirect") ?: "/logged-out/");
}
