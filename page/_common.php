<?php
namespace Authwave\Page;

use Authwave\Application\ApplicationDeployment;
use Authwave\DataTransfer\RequestData;
use Gt\WebEngine\Logic\Page;

class _CommonPage extends Page {
	public RequestData $requestData;
	public ApplicationDeployment $deployment;

	public function go():void {
		if($this->server->getRequestUri()->getPath() !== "/setup"
		&& !isset($this->requestData)) {
			$this->redirect($this->deployment->getClientHost());
			exit;
		}
	}
}