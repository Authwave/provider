<?php
namespace Authwave\Page\Login;

use Authwave\Application\ApplicationDeployment;
use Gt\DomTemplate\Element;
use Gt\WebEngine\Logic\Page;

class _CommonPage extends Page {
	public ApplicationDeployment $deployment;

	public function go():void {
		$this->logo(
			$this->document->querySelector(".c-main-login")
		);
	}

	private function logo(Element $loginContainer):void {
		$loginContainer->bindKeyValue(
			"applicationName",
			$this->deployment->getApplication()->getDisplayName()
		);
		$logo
	}
}