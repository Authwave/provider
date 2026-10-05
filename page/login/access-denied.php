<?php
use Authwave\Session\LoginSession;
use Authwave\User\LoginState;
use Gt\Http\Request;
use Gt\Http\Response;

function go(
	LoginSession $loginSession,
	Response $response,
):void {
	if($loginSession->getState() !== LoginState::LOGGED_IN) {
		$loginSession->requestAdmin();
		$response->redirect("/login/");
		return;
	}

	$response->redirect("/admin/");
}

function do_switch_account(
	Request $request,
	LoginSession $loginSession,
	Response $response,
):void {
	// Error rendering can run page actions without CSRF validation. Only accept
	// this action through the page's own, normally validated POST endpoint.
	if($request->getMethod() !== "POST"
	|| rtrim($request->getUri()->getPath(), "/") !== "/login/access-denied") {
		return;
	}

	$loginSession->clearDataForLogout($loginSession->getDeployment());
	$loginSession->requestAdmin();
	$response->redirect("/login/");
}
