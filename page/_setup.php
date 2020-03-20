<?php
namespace Authwave\Page;

use Authwave\Application\ApplicationNotFoundForHostException;
use Authwave\Application\ApplicationRepository;
use Authwave\DataTransfer\RequestData;
use Authwave\User\UserRepository;
use Gt\WebEngine\Logic\PageSetup;

class _SetupPage extends PageSetup {
	const CONFIG_PATH = "/config";

	public function go():void {
		$this->request();
		$this->app();
		$this->user();
	}

	private function request():void {
		$requestData = $this->getRequestFromQuery(
			$this->server->getQueryParams()
		);

		if($requestData) {
			$this->session->set(
				RequestData::SESSION_REQUEST_DATA,
				$requestData
			);
			$this->redirect(
				$this->server->getRequestUri()->getPath()
			);
			exit;
		}
		else {
			/** @var RequestData $requestData */
			$requestData = $this->session->get(
				RequestData::SESSION_REQUEST_DATA
			);
		}

		$this->logicProperty->set("requestData", $requestData);
	}

	private function app():void {
		$uri = $this->server->getRequestUri();

		if($uri->getPath() === self::CONFIG_PATH) {
			return;
		}

		$appRepo = new ApplicationRepository(
			$this->database->queryCollection("application")
		);

		$this->logicProperty->set("appRepo", $appRepo);

		try {
			$deployment = $appRepo->getApplicationByLoginHost($uri);
			$this->logicProperty->set("deployment", $deployment);
		}
		catch(ApplicationNotFoundForHostException $exception) {
			$this->redirect(self::CONFIG_PATH);
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

	private function getRequestFromQuery(array $params):?RequestData {
		if(!isset($params["cipher"])
		|| !isset($params["iv"])
		|| !isset($params["path"])) {
			return null;
		}

		return new RequestData(
			$params["cipher"],
			$params["iv"],
			$params["path"]
		);
	}
}