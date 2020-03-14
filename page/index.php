<?php
namespace Authwave\Page;

use Gt\WebEngine\Logic\Page;

class IndexPage extends Page {
	function go() {
		$authenticated = false;
		if(!$authenticated) {
			$this->redirect(
				"/login?" . $this->server->getQueryString(),
				303
			);
			exit;
		}
	}
}