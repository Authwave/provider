<?php
use Authwave\Session\LoginSession;
use Authwave\User\LoginState;
use Authwave\User\UserRepository;
use Gt\Cipher\InitVector;
use Gt\Cipher\Key;
use Gt\Cipher\Message\EncryptedMessage;
use Gt\Cipher\Message\PlainTextMessage;
use Gt\DomTemplate\Binder;
use Gt\Http\Response;
use Gt\Input\Input;
use Gt\Logger\Log;
use Gt\Session\Session;

function go(
	Input $input,
	Response $response,
	Binder $binder,
	LoginSession $loginSession,
	UserRepository $userRepo,
	Session $session,
):void {
	if($loginSession->getState() !== LoginState::LOGGED_IN) {
		$response->redirect("/login/");
	}

	$secretIvB64 = $loginSession->getDataKey("secretIv");
	$secretIvB64 = strtr($secretIvB64, " ", "+");
	$secretIvBytes = base64_decode($secretIvB64);
	$secretIv = (new InitVector())->withBytes($secretIvBytes);

	$deployment = $loginSession->getDeployment();
	$userDataMessage = new PlainTextMessage(
		json_encode([
			"id" => $userRepo->get($deployment, $loginSession->getEmail())->id,
			"email" => $loginSession->getEmail(),
		]),
		$secretIv,
	);
	$returnUri = $deployment->getClientReturnUri();
	$cipherText = $userDataMessage->encrypt(new Key($deployment->secret));

	$queryString = http_build_query([
		"AUTHWAVE_RESPONSE_DATA" => (string)$cipherText,
	]);

	$binder->bindKeyValue("returnUri", "$returnUri?$queryString");
	$session->kill();

	if(!$input->contains("debug")) {
		$response->redirect("$returnUri?$queryString");
	}
}
