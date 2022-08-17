<?php
use Authwave\Session\LoginSession;
use Authwave\User\LoginState;
use Authwave\User\UserRepository;
use Gt\Cipher\InitVector;
use Gt\Cipher\Message\EncryptedMessage;
use Gt\Cipher\Message\PlainTextMessage;
use Gt\DomTemplate\DocumentBinder;
use Gt\Http\Response;
use Gt\Logger\Log;
use Gt\Session\Session;

function go(
	Response $response,
	DocumentBinder $binder,
	LoginSession $loginSession,
	UserRepository $userRepo,
	Session $session,
):void {
	if($loginSession->getState() !== LoginState::LOGGED_IN) {
		$response->redirect("/login/");
	}

	$secretIvB64 = $loginSession->getData("secretIv");
	$secretIvB64 = strtr($secretIvB64, " ", "+");
	$secretIvBytes = base64_decode($secretIvB64);
	$secretIv = (new InitVector())->withBytes($secretIvBytes);

	$site = $loginSession->getSite();
	$userDataMessage = new PlainTextMessage(
		json_encode([
			"email" => $loginSession->getEmail(),
			"id" => $userRepo->get($site, $loginSession->getEmail())->id,
		]),
		$secretIv,
	);
	$returnUri = $site->uri;
	$cipherText = $userDataMessage->encrypt($site->key);

	$queryString = http_build_query([
		"AUTHWAVE_RESPONSE_DATA" => (string)$cipherText,
	]);

	$binder->bindKeyValue("returnUri", "$returnUri?$queryString");
	$session->kill();
}
