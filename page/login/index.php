<?php
namespace Authwave\Page\Login;

use Authwave\DataTransfer\RequestData;
use Gt\Input\InputData\InputData;
use Gt\WebEngine\Logic\Page;

class IndexPage extends Page {
	public function go():void {
		$data = $this->database->fetchAll("test/getAll");
		var_dump($data);die();

		$this->storeRequestData(
			$this->server->getQueryParams()
		);

		var_dump($this->session->get(RequestData::SESSION_REQUEST_DATA));die();
	}

	public function doContinue(InputData $data):void {
		$this->redirect("/login/authenticate");
		exit;
	}

	private function storeRequestData(array $params):void {
		if(!isset($params["id"])
		|| !isset($params["cipher"])
		|| !isset($params["iv"])
		|| !isset($params["path"])) {
			return;
		}

		$requestData = new RequestData(
			$params["id"],
			$params["cipher"],
			$params["iv"],
			$params["path"]
		);
		$this->session->set(RequestData::SESSION_REQUEST_DATA, $requestData);
		$this->redirect($this->server->getRequestUri()->getPath());
		exit;
	}
}