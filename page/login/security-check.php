<?php
use Authwave\Session\FlashSession;
use Authwave\Session\LoginSession;
use Authwave\User\LoginState;
use Authwave\User\UserRepository;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\DocumentBinder;
use Gt\DomTemplate\TemplateCollection;
use Gt\Http\Response;
use Gt\Input\Input;
use Gt\Logger\Log;
use Gt\Session\Session;

function go(
	LoginSession $loginSession,
	FlashSession $flash,
	HTMLDocument $document,
	DocumentBinder $binder,
	TemplateCollection $templateCollection,
	UserRepository $userRepo,
):void {
	$userRepo->cleanOldTokens();
	$email = $loginSession->getEmail();
	$binder->bindKeyValue("email", $email);

	if($errorMessage = $flash->getFlash("error")) {
		$t = $templateCollection->get($document, "error");
		$binder->bindValue($errorMessage, $t->insertTemplate());
	}
}

function do_confirm(
	Input $input,
	Response $response,
	LoginSession $loginSession,
	FlashSession $flash,
	UserRepository $userRepo,
):void {
	usleep(rand(500_000, 1_500_000));
	$email = $loginSession->getEmail();
	$site = $loginSession->getSite();
	$user = $userRepo->get($site, $email);

	if(!$user) {
		$response->redirect("/login/");
	}

	$token = $userRepo->getLatestSecurityToken($user->id);

	if(is_null($token) || $input->getString("token") !== $token) {
		$flash->setFlash("The code you entered is incorrect.", "error");
		$response->reload();
	}

	$userRepo->consumeToken($user->id, $token);
	$loginSession->setState(LoginState::LOGGED_IN);
	$response->redirect("/login/success");
}
