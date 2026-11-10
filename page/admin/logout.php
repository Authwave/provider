<?php
use Authwave\Admin\Onboarding\AdminArea;
use GT\Config\Config;
use GT\Http\Request;
use GT\Http\Response;
use GT\Session\Session;
use GT\Cookie\CookieHandler;

function do_logout(
	AdminArea $adminArea,
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

	$adminArea->logout($session, $cookies);
	$response->redirect($config->getString("authwave.logout_redirect") ?: "/logged-out/");
}
