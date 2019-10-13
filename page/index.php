<?php
namespace Authwave\Page;

use Gt\WebEngine\Logic\Page;

class IndexPage extends Page {
	function go() {
		$this->document->bindData([
			"applicationName" => "Authwave",
			"applicationLogoSrc" => "/asset/image/authwave-logo.png",
		]);
	}

	function doNext() {
		die("Next!");
	}

	function doCreate() {
		die("Create!");
	}

	function doForgot() {
		die("Forgot!");
	}
}