<?php
namespace Authwave\Admin\Onboarding;

use Authwave\Model\ApplicationRepository;
use Authwave\Security\AdminAccess;
use Authwave\Security\AdminAccessDenied;
use Authwave\Session\LoginSession;
use Authwave\User\LoginState;
use Authwave\Model\User;
use Authwave\User\UserRepository;
use GT\Http\Response;
use GT\Http\Uri;
use GT\Session\Session;
use GT\Http\ResponseStatusException\ClientError\HttpNotFound;

class AdminArea {
	private ?Onboarding $onboarding = null;

	public function __construct(
		private readonly LoginSession $login,
		private readonly UserRepository $users,
		private readonly AdminAccess $access,
		private readonly ApplicationRepository $applications,
	) {}

	public function enter(Uri $uri, Response $response):void {
		if(!$this->login->getDeployment()) {
			$host = $uri->getHost();

			if($uri->getPort() && $uri->getPort() !== 443) {
				$host .= ":" . $uri->getPort();
			}

			$this->login->setDeploymentForLogin(
				$this->applications->getDeploymentByProviderHost($host) ?? throw new HttpNotFound(),
			);
		}

		if($this->login->getState() !== LoginState::LOGGED_IN) {
			$this->login->requestAdmin();
			$response->redirect("/login/");
			return;
		}

		$onboarding = $this->onboarding();
		$this->login->clearAdminRequest();

		if($redirect = $onboarding->redirectFor($uri)) {
			$response->redirect($redirect);
		}
	}

	public function logout(Session $session):void {
		$administrator = $this->administrator();
		$this->login->clearDataForLogout($administrator->deployment, $administrator);
		$session->kill();
	}

	public function onboarding():Onboarding {
		if($this->onboarding === null) {
			$this->onboarding = new Onboarding($this->applications->countFor($this->administrator()),
				$this->applications->hasVerifiedDomain($this->administrator()),
				$this->applications->hasConnectedApplication($this->administrator()),
				$this->applications->hasClientLogin($this->administrator()));
		}

		return $this->onboarding;
	}

	public function administrator():User {
		$deployment = $this->login->getDeployment();
		$email = $this->login->getEmail();

		if($this->login->getState() !== LoginState::LOGGED_IN || !$deployment || !$email) {
			throw new AdminAccessDenied("An authenticated administrator is required.");
		}

		$user = $this->users->get($deployment, $email);

		if(!$this->access->allows($user) || $user === null) {
			throw new AdminAccessDenied("Administrator access is required.");
		}

		return $user;
	}
}
