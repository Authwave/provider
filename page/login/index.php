<?php
namespace Authwave\Page\Login;

use Authwave\DataTransfer\LoginData;
use Authwave\DataTransfer\RequestData;
use Gt\DomTemplate\Element;
use Gt\Input\InputData\InputData;
use Gt\WebEngine\Logic\Page;

class IndexPage extends Page {
	public function go():void {
		$this->prefill(
			$this->document->querySelector("[name=email]"),
			$this->input->contains("email")
		);
		$this->session->remove(LoginData::SESSION_LOGIN_DATA);
	}

	public function doContinue(InputData $data):void {
		$loginData = new LoginData($data->getString("email"));
		$this->session->set(LoginData::SESSION_LOGIN_DATA, $loginData);

		$this->redirect("/login/authenticate");
	}

	private function prefill(Element $emailInput, bool $hasEmail):void {
		if(!$hasEmail) {
			return;
		}

		/** @var LoginData $loginData */
		$loginData = $this->session->get(LoginData::SESSION_LOGIN_DATA);
		if(!$loginData) {
			return;
		}

		$emailInput->value = $loginData->getEmail();
	}
}