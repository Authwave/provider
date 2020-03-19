<?php
namespace Authwave\Page\Login;

use Authwave\Application\ApplicationDeployment;
use Authwave\DataTransfer\LoginData;
use Authwave\DataTransfer\RequestData;
use Gt\DomTemplate\Element;
use Gt\Input\InputData\InputData;
use Gt\WebEngine\Logic\Page;
use TypeError;

class AuthenticatePage extends Page {
	public ApplicationDeployment $deployment;
	private RequestData $requestData;
	private LoginData $loginData;

	public function go():void {
		$this->storeLoginData();
		$this->outputEmailAddress();
		$this->outputProviders(
			$this->document->querySelector(".auth-option.social")
		);
	}

	public function doPassword(InputData $data):void {
		$this->login(
			LoginData::TYPE_PASSWORD,
			$data->getString("password")
		);
	}

	public function doEmail(InputData $data):void {
		$this->login(
			LoginData::TYPE_EMAIL
		);
	}

	public function doSocialGoogle():void {
		$this->login(
			LoginData::TYPE_SOCIAL,
			LoginData::SOCIAL_GOOGLE
		);
	}

	public function doSocialTwitter():void {
		$this->login(
			LoginData::TYPE_SOCIAL,
			LoginData::SOCIAL_TWITTER
		);
	}

	public function doSocialFacebook():void {
		$this->login(
			LoginData::TYPE_SOCIAL,
			LoginData::SOCIAL_FACEBOOK
		);
	}

	public function doSocialLinkedIn():void {
		$this->login(
			LoginData::TYPE_SOCIAL,
			LoginData::SOCIAL_LINKEDIN
		);
	}

	public function doSocialGithub():void {
		$this->login(
			LoginData::TYPE_SOCIAL,
			LoginData::SOCIAL_GITHUB
		);
	}

	public function doSocialMicrosoft():void {
		$this->login(
			LoginData::TYPE_SOCIAL,
			LoginData::SOCIAL_MICROSOFT
		);
	}

	private function login(string $type, string $data = null):void {
		$this->storeLoginData();
		var_dump($this->requestData);die();
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

	private function storeLoginData():void {
		try {
			$this->loginData = $this->session->get(
				LoginData::SESSION_LOGIN_DATA
			);
		}
		catch(TypeError $error) {
			$this->redirect("/login");
			exit;
		}

		if($this->session->contains(RequestData::SESSION_REQUEST_DATA)) {
			$this->requestData = $this->session->get(
				RequestData::SESSION_REQUEST_DATA
			);
		}
	}

	private function outputEmailAddress():void {
		/** @var LoginData $loginData */
		$loginData = $this->session->get(LoginData::SESSION_LOGIN_DATA);
		$this->document->bindKeyValue("email", $loginData->getEmail());
	}
}