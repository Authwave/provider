<?php
use Authwave\Session\LoginSession;
use Authwave\User\LoginState;
use Authwave\User\UserRepository;
use Gt\DomTemplate\DocumentBinder;
use Gt\Http\Response;
use Gt\Input\Input;

function go(
	Response $response,
	LoginSession $loginSession,
	DocumentBinder $binder,
):void {
	$email = $loginSession->getEmail();

	if(!$email) {
		$response->redirect("/login/");
	}

	$binder->bindKeyValue("email", $email);
}

function do_password(
	Input $input,
	UserRepository $userRepo,
	LoginSession $loginSession,
	Response $response,
):void {
	usleep(rand(500_000, 1_500_000));
	$email = $loginSession->getEmail();
	$site = $loginSession->getSite();
	$password = $input->getString("password");

	if($user = $userRepo->get($site, $email)) {
		if($userRepo->checkLogin($user, $password)) {
			$loginSession->setState(LoginState::LOGGED_IN);
			$response->redirect("/login/success/");
		}
		else {
			// todo: hook up the password after proving security!
			$userRepo->generateSecurityToken(
				$user->id,
				$password,
			);

		}
	}
	else {
		$userRepo->create(
			$site,
			$email,
			$password,
		);
	}

	$response->redirect("/login/security-check/");
}
