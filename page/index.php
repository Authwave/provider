<?php
namespace Authwave\Page;

use Authwave\Application\ApplicationDeployment;
use Authwave\Crypto\Secret;
use Authwave\DataTransfer\RequestData;
use Gt\Http\Uri;
use Gt\WebEngine\Logic\Page;

class IndexPage extends Page {
	public RequestData $requestData;
	public ApplicationDeployment $deployment;

	public function go():void {
		$this->handleAction();
	}

	private function handleAction():void {
		$secret = new Secret(
			$this->requestData,
			$this->deployment->getClientKey()
		);

		switch($secret->getMessageKey("action")) {
		case "logout":
			$this->session->kill();
			// TODO: Redirect with confirmation of logout.
			break;

		default:
			$redirectUri = (new Uri())
				->withPath("/login")
				->withQuery($this->server->getQueryString());
			$this->redirect(
				$redirectUri,
				303
			);
		}
	}
}