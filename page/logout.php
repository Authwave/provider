<?php
namespace Authwave\Page;

use Gt\WebEngine\Logic\Page;

class LogoutPage extends Page {
	public function go():void {
		$this->session->kill();
		$this->reload();
	}
}