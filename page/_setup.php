<?php
namespace Authwave\Page;

use Authwave\Application\ApplicationNotFoundForHostException;
use Authwave\Application\ApplicationRepository;
use Authwave\User\UserRepository;
use Gt\WebEngine\Logic\PageSetup;

class _SetupPage extends PageSetup {
	const SETUP_PATH = "/setup";

	public function go():void {
		$this->app();
		$this->user();
	}

	private function app():void {
		$uri = $this->server->getRequestUri();

		if($uri->getPath() === self::SETUP_PATH) {
			return;
		}

		$appRepo = new ApplicationRepository(
			$this->database->queryCollection("application")
		);

		$this->logicProperty->set("appRepo", $appRepo);

		try {
			$deployment = $appRepo->getApplicationByHost($uri);
			$this->logicProperty->set("deployment", $deployment);
		}
		catch(ApplicationNotFoundForHostException $exception) {
			$this->redirect(self::SETUP_PATH);
			exit;
		}
	}

	private function user():void {
		$userRepo = new UserRepository(
			$this->database->queryCollection("user"),
			$this->session->getStore(UserRepository::SESSION_KEY, true)
		);

		$this->logicProperty->set("userRepo", $userRepo);
	}
}