<?php
namespace Authwave\Session;

use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\User\LoginState;
use Gt\Session\SessionStore;

class LoginSession {
	const SESSION_STORE_KEY = "AUTHWAVE_PROVIDER_SESSION";

	public function __construct(
		private SessionStore $session,
	) {
	}

	public function getDeployment():?ApplicationDeployment {
		return $this->session->get("deployment");
	}

	public function setDeployment(ApplicationDeployment $deployment):void {
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

	public function clearData():void {
		$this->session->remove("email");
	}

	public function getEmail():?string {
		return $this->session->getString("email");
	}

	public function setEmail(string $email):void {
		$this->session->set("email", $email);
	}

	public function setState(LoginState $state):void {
		$this->session->set(LoginState::class, $state);
	}

	public function getState():LoginState {
		return $this->session->get(LoginState::class)
			?? LoginState::NOT_LOGGED_IN;
	}
}
