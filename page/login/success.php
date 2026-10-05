<?php
use Authwave\Session\LoginSession;
use Authwave\Security\Action;
use Authwave\Security\Audit;
use Authwave\Security\AdminAccess;
use Authwave\User\LoginState;
use Authwave\User\UserRepository;
use Gt\Cipher\InitVector;
use Gt\Cipher\Key;
use Gt\Cipher\Message\PlainTextMessage;
use Gt\DomTemplate\Binder;
use Gt\Http\Response;
use Gt\Http\Request;
use Gt\Input\Input;
use Gt\Session\Session;

function go(
	Input $input,
	Response $response,
	Binder $binder,
	LoginSession $loginSession,
	UserRepository $userRepo,
	Session $session,
	Audit $audit,
	AdminAccess $adminAccess,
	Request $request,
):void {
	if($loginSession->getState() !== LoginState::LOGGED_IN) {
		$response->redirect("/login/");
		return;
	}

	$deployment = $loginSession->getDeployment();
	$user = $userRepo->get($deployment, $loginSession->getEmail());
	if(!$user) {
		$loginSession->clearDataForLogout($deployment);
		$response->redirect("/login/");
		return;
	}
	$isAdmin = $adminAccess->allows($user);
	if($loginSession->isAdminRequested() || !$loginSession->getDataKey("secretIv")) {
		$loginSession->clearAdminRequest();
		$audit->create(Action::LOGIN_COMPLETED, ["deploymentId" => $deployment->id], $user);
		$response->redirect("/admin/");
		return;
	}
	$binder->bindKeyValue("isAdmin", $isAdmin);

	$secretIvB64 = $loginSession->getDataKey("secretIv");
	$secretIvB64 = strtr($secretIvB64, " ", "+");
	$secretIvBytes = base64_decode($secretIvB64);
	$secretIv = (new InitVector())->withBytes($secretIvBytes);

	$userDataMessage = new PlainTextMessage(
		json_encode([
			"id" => $user->id,
			"email" => $loginSession->getEmail(),
		]),
		$secretIv,
	);
	$returnUri = $deployment->getClientReturnUri();
	$cipherText = $userDataMessage->encrypt(new Key($deployment->secret));

	if($returnQuery = $loginSession->getDataKey("returnQuery")) {
		$returnUri = $returnUri->withQuery($returnQuery);
	}
	$queryString = $returnUri->getQuery();
	if($queryString) {
		$queryString .= "&";
	}
	$queryString .= http_build_query([
		"AUTHWAVE_RESPONSE_DATA" => (string)$cipherText,
	]);
	$returnUri = $returnUri->withQuery($queryString);

	$binder->bindKeyValue("returnUri", (string)$returnUri);
	$audit->create(Action::LOGIN_COMPLETED, [
		"deploymentId" => $deployment->id,
	], $user);
	if(!$isAdmin) {
		$session->kill();
	}

	$continueAutomatically = !$isAdmin && !$input->contains("debug");
	$binder->bindKeyValue("continueAutomatically", $continueAutomatically);
	// Let Flux finish on this origin; the rendered link performs the browser
	// navigation to the client, which must not be followed by fetch/CORS.
	if($continueAutomatically && $request->getHeaderLine("X-Authwave-Flux") !== "1") {
		$response->redirect($returnUri);
	}
}
