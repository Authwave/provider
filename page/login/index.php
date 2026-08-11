<?php
use Authwave\Model\ApplicationDeployment;
use Authwave\Model\ApplicationRepository;
use Authwave\Session\LoginSession;
use Authwave\Security\Action;
use Authwave\Security\AnonUser;
use Authwave\Security\Audit;
use Gt\DomTemplate\Binder;
use Gt\Http\Request;
use Gt\Http\Response;
use Gt\Http\ServerInfo;
use Gt\Input\Input;
use Gt\Session\Session;

function go(
	Input $input,
	Request $request,
	Response $response,
	LoginSession $loginSession,
	Binder $binder,
):void {
	$deployment = $loginSession->getDeployment();

	if($email = $input->getString("email")) {
		if($request->getMethod() === "GET") {
			$loginSession->clearDataForLogout($deployment);
		}

		$binder->bindKeyValue("email", $email);
	}

	if($loginSession->getEmail()) {
		$response->redirect("/login/authenticate/");
	}

	$clientHost = $deployment->clientHost;
	$binder->bindKeyValue("clientHost", $clientHost);
	$binder->bindKeyValue("title", $deployment->title);
}

function do_continue(
	Input $input,
	Response $response,
	LoginSession $loginSession,
	Audit $audit,
	AnonUser $anonUser,
):void {
	if($email = $input->getString("email") ?? $loginSession->getEmail()) {
		$loginSession->setEmail($email);
		$response->redirect("/login/authenticate/");
	}
	else {
		$audit->create(Action::EMAIL_REJECTED, [
			"deploymentId" => $loginSession->getDeployment()->id,
			"reason" => "missing_email",
		], $anonUser);
		// TODO: DomValidation
	}
}

function do_cancel(
	Response $response,
	Session $session,
	LoginSession $loginSession,
	Audit $audit,
	AnonUser $anonUser,
):void {
	$deployment = $loginSession->getDeployment();
	$audit->create(Action::LOGIN_CANCELLED, [
		"deploymentId" => $deployment->id,
	], $anonUser);
	if(strtok($deployment->getClientReturnUri()->getHost(), ":") !== "localhost") {
		$session->kill();
	}
	$response->redirect($deployment->getClientReturnUri()->withQueryValue("do", "cancel"));
}
