<?php
namespace Authwave\Page;

use Authwave\Application\ApplicationDeployment;
use Authwave\DataTransfer\RequestData;
use Gt\Http\Uri;
use Gt\WebEngine\Logic\Page;

class IndexPage extends Page {
	public function go():void {
		$authenticated = false;
		if(!$authenticated) {
			$redirectUri = (new Uri())
				->withPath("/login")
				->withQuery($this->server->getQueryString());
			$this->redirect(
				$redirectUri,
				303
			);
			exit;
		}
	}
}