<?php
namespace Authwave\Page\Login;

use Authwave\Application\ApplicationDeployment;
use Gt\DomTemplate\Element;
use Gt\WebEngine\Logic\Page;

class _CommonPage extends Page {
	public ApplicationDeployment $deployment;

	public function go():void {
		$this->logo(
			$this->document->querySelector("form")
		);
	}

	private function logo(Element $loginContainer):void {
		$displayName = $this->deployment->getApplication()
			->getDisplayName();
		$lcDisplayName = strtolower($displayName);

		$loginContainer->bindKeyValue(
			"applicationName",
			$displayName
		);
		$loginContainer->bindKeyValue(
			"applicationLogoSrc",
			"/asset/applicationLogo/$lcDisplayName.svg"
		);
	}
}