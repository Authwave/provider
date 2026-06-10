<?php
use Authwave\Session\FlashSession;
use Authwave\Session\LoginSession;
use Authwave\User\LoginState;
use Authwave\User\UserRepository;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\Binder;
use Gt\DomTemplate\ListElementCollection;
use Gt\Http\Response;
use Gt\Input\Input;

function go(
	LoginSession $loginSession,
	FlashSession $flash,
	HTMLDocument $document,
	Binder $binder,
	ListElementCollection $listElementCollection,
	UserRepository $userRepo,
):void {
	$userRepo->cleanOldAuthCodes();
	$email = $loginSession->getEmail();
	$binder->bindKeyValue("email", $email);

	if($errorMessage = $flash->getFlash("error")) {
		$t = $listElementCollection->get($document, "error");
		$binder->bindValue($errorMessage, $t->insertListItem());
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
	$site = $loginSession->getDeployment();
	$user = $userRepo->get($site, $email);

	if(!$user) {
		$response->redirect("/login/");
	}

	$token = $userRepo->getLatestAuthCode($user->id);

	if(is_null($token) || $input->getString("token") !== $token) {
		$flash->setFlash("The code you entered is incorrect.", "error");
		$response->reload();
	}

	$userRepo->consumeAuthCode($user->id, $token);
	$loginSession->setState(LoginState::LOGGED_IN);
	$response->redirect("/login/success/");
}
