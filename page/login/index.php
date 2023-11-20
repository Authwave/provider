<?php
use Authwave\Session\LoginSession;
use Gt\DomTemplate\Binder;
use Gt\Http\Request;
use Gt\Http\Response;
use Gt\Input\Input;
use Gt\Session\Session;

function go(
	Input $input,
	Request $request,
	Response $response,
	LoginSession $loginSession,
	Binder $binder,
):void {
	if($email = $input->getString("email")) {
		if($request->getMethod() === "GET") {
			$loginSession->clearData();
		}

		$binder->bindKeyValue("email", $email);
	}

	if($loginSession->getEmail()) {
		$response->redirect("/login/authenticate/");
	}
}

function do_continue(
	Input $input,
	Response $response,
	LoginSession $loginSession,
):void {
	if($email = $input->getString("email") ?? $loginSession->getEmail()) {
		$loginSession->setEmail($email);
		$response->redirect("/login/authenticate/");
	}
	else {
		// TODO: Show "please fill in your email address" error.
	}
}

function do_cancel(
	Response $response,
	Session $session,
	LoginSession $loginSession,
):void {
	$deployment = $loginSession->getDeployment();
	if(strtok($deployment->getClientReturnUri()->getHost(), ":") !== "localhost") {
		$session->kill();
	}
	$response->redirect($deployment->getClientReturnUri()->withQueryValue("do", "cancel"));
}
