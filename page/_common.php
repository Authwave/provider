<?php
namespace Authwave\Page;

use Authwave\Application\ApplicationDeployment;
use Authwave\DataTransfer\RequestData;
use Gt\WebEngine\Logic\Page;

class _CommonPage extends Page {
	public RequestData $requestData;
	public ApplicationDeployment $deployment;

	public function go():void {
		$this->handleRedirect();
	}

	private function handleRedirect():void {
		if(!isset($this->requestData)) {
			if($this->server->getRequestUri()->getPath() === "/config") {
				if(isset($this->deployment)) {
					$this->redirect($this->deployment->getClientHost());
					exit;
				}
			}
			else {
				$this->redirect($this->deployment->getClientHost());
				exit;
			}
		}
	}
}