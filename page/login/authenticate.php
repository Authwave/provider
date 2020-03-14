<?php
namespace Authwave\Page\Login;

use Gt\DomTemplate\Element;
use Gt\Input\InputData\InputData;
use Gt\WebEngine\Logic\Page;

class AuthenticatePage extends Page {
	public function go():void {
		$this->outputProviders(
			$this->document->querySelector(".auth-option.social")
		);
	}

	public function doPassword(InputData $data):void {
		$this->redirect("/login/check-email");
		exit;
	}

	public function doEmail(InputData $data):void {

	}

	public function doSocialGoogle():void {

	}

	public function doSocialTwitter():void {

	}

	public function doSocialFacebook():void {

	}

	public function doSocialLinkedIn():void {

	}

	public function doSocialGithub():void {

	}

	public function doSocialMicrosoft():void {

	}

	private function outputProviders(Element $outputTo):void {
		$providers = [
			"Google",
			"Facebook",
			"Twitter",
			"LinkedIn",
			"Github",
			"Microsoft",
		];

		$outputTo->bindList($providers);
	}
}