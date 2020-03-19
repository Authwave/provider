<?php
namespace Authwave\Page;

use Gt\Http\Uri;
use Gt\WebEngine\Logic\Page;

class IndexPage extends Page {
	function go() {
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