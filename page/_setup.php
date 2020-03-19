<?php
namespace Authwave\Page;

use Authwave\User\UserRepository;
use Gt\WebEngine\Logic\PageSetup;

class _SetupPage extends PageSetup {
	public function go():void {
		$userRepo = new UserRepository(
			$this->database->queryCollection("user"),
			$this->session->getStore(UserRepository::SESSION_KEY, true)
		);

		$this->logicProperty->set("userRepo", $userRepo);
	}
}