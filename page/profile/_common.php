<?php
namespace Authwave\Page\Profile;

use Authwave\Application\ApplicationDeployment;
use Authwave\User\User;
use Gt\DomTemplate\Element;
use Gt\WebEngine\Logic\Page;

class _CommonPage extends Page {
	public ApplicationDeployment $deployment;
	public User $user;

	public function go():void {
		if(!isset($this->user)) {
			$this->redirect("/");
		}

		$displayName = $this->deployment->getApplication()->getDisplayName();
		$lcDisplayName = strtolower($displayName);

		$this->document->bindKeyValue(
			"applicationName",
			$displayName
		);
		$this->document->bindKeyValue(
			"applicationLogoSrc",
			"/asset/applicationLogo/$lcDisplayName.svg"
		);

		$this->document->bindKeyValue(
			"clientHost",
			$this->deployment->getClientHost()
		);
		$this->document->bindKeyValue(
			"email",
			$this->user->getEmail()
		);

		$this->nav(
			$this->document->querySelector("body>nav"),
			$this->server->getRequestUri()->getPath()
		);
	}

	public function nav(Element $element, string $uriPath):void {
		foreach($element->querySelectorAll("a") as $link) {
			if($link->href === $uriPath) {
				$link->closest("li")->classList->add("selected");
			}
		}
	}
}