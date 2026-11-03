<?php
use Authwave\Admin\Onboarding\AdminArea;
use Gt\Config\Config;
use Gt\Http\Request;
use Gt\Http\Response;
use Gt\Session\Session;

function do_logout(
	AdminArea $adminArea,
	Request $request,
	Response $response,
	Session $session,
	Config $config,
):void {
	if($request->getMethod() !== "POST"
	|| rtrim($request->getUri()->getPath(), "/") !== "/admin/logout") {
		return;
	}

	$adminArea->logout($session);
	$response->redirect($config->getString("authwave.logout_redirect") ?: "/logged-out/");
}
