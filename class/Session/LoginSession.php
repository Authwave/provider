<?php
namespace Authwave\Session;

use Authwave\Model\ApplicationDeployment;
use Authwave\Security\Action;
use Authwave\Security\AnonUser;
use Authwave\Security\Audit;
use Authwave\User\LoginState;
use Gt\Session\SessionStore;

class LoginSession {
	const SESSION_STORE_KEY = "AUTHWAVE_PROVIDER_SESSION";

	public function __construct(
		private SessionStore $session,
		private readonly Audit $audit,
		private readonly AnonUser $anonUser,
	) {
	}

	public function getDeployment():?ApplicationDeployment {
		return $this->session->get("deployment");
	}

	public function setDeploymentForLogin(ApplicationDeployment $deployment):void {
		$this->session->remove("email");
		$this->session->remove("data");
		$this->session->remove("adminRequested");
		$this->setState(LoginState::NOT_LOGGED_IN);
		$this->audit->create(Action::LOGIN_REQUESTED, [
			"deploymentId" => $deployment->id,
		], $this->anonUser);
		$this->session->set("deployment", $deployment);
	}

	/** @param array<string, string> $kvp */
	public function setData(array $kvp):void {
		$this->session->set("data", $kvp);
	}

	public function getDataKey(string $key):?string {
		$kvp = $this->session->get("data");
		return $kvp[$key] ?? null;
	}

	public function clearDataForLogout(ApplicationDeployment $deployment):void {
		$this->audit->create(Action::LOGOUT_REQUESTED, [
			"deploymentId" => $deployment->id,
		], $this->anonUser);
		$this->session->remove("email");
		$this->setState(LoginState::NOT_LOGGED_IN);
	}

	public function getEmail():?string {
		return $this->session->getString("email");
	}

	public function setEmail(string $email):void {
		if($email !== $this->getEmail()) {
			$this->setState(LoginState::NOT_LOGGED_IN);
		}
		$this->audit->create(Action::EMAIL_SUBMITTED, [
			"deploymentId" => $this->getDeployment()->id,
			"email" => $email,
		], $this->anonUser);
		$this->session->set("email", $email);
	}

	public function setState(LoginState $state):void {
		$this->session->set(LoginState::class, $state);
	}

	public function getState():LoginState {
		return $this->session->get(LoginState::class)
			?? LoginState::NOT_LOGGED_IN;
	}

	public function requestAdmin():void {
		$this->session->set("adminRequested", true);
	}

	public function isAdminRequested():bool {
		return $this->session->get("adminRequested") === true;
	}

	public function clearAdminRequest():void {
		$this->session->remove("adminRequested");
	}
}
