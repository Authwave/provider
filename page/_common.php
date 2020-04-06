<?php
namespace Authwave\Page;

use Authwave\Application\ApplicationDeployment;
use Authwave\DataTransfer\RequestData;
use Authwave\UI\Flash;
use Gt\DomTemplate\TemplateComponentNotFoundException;
use Gt\WebEngine\Logic\Page;

class _CommonPage extends Page {
	public RequestData $requestData;
	public ApplicationDeployment $deployment;
	public Flash $flash;

	public function go():void {
		$this->handleRedirect();
		$this->flash();
	}

	private function handleRedirect():void {
		$uriPath = $this->server->getRequestUri()->getPath();

		if(!isset($this->requestData)) {
			if($uriPath === "/admin") {
				return;
			}
			elseif($uriPath === "/config") {
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

	private function flash():void {
		foreach($this->flash->getQueue() as $type => $list) {
			foreach($list as $message) {
				try {
					$t = $this->document->getTemplate(
						"ui-flash-message"
					);
				}
				catch(TemplateComponentNotFoundException $exception) {
					break(2);
				}

				$t->bindValue($message);
				$inserted = $t->insertTemplate();
				$inserted->classList->add($type);
			}
		}

		$this->flash->clear();
	}
}