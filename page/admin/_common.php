<?php
use Authwave\Security\AdminAccess;
use Authwave\Session\LoginSession;
use Authwave\User\LoginState;
use Authwave\User\UserRepository;
use Gt\DomTemplate\Binder;
use Gt\Http\Response;
use Authwave\Security\AdminAccessDenied;

function go(
	LoginSession $loginSession,
	UserRepository $userRepo,
	AdminAccess $adminAccess,
	Response $response,
	Binder $binder,
):void {
	if($loginSession->getState() !== LoginState::LOGGED_IN) {
		$loginSession->requestAdmin();
		$response->redirect("/login/");
		return;
	}

	$user = $userRepo->get($loginSession->getDeployment(), $loginSession->getEmail());
	if(!$adminAccess->allows($user)) {
		throw new AdminAccessDenied("Administrator access is required.");
	}

	$loginSession->clearAdminRequest();
	$binder->bindKeyValue("title", "User Administration");
}
