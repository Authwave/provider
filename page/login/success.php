<?php
namespace Authwave\Page\Login;

use Gt\WebEngine\Logic\Page;

class SuccessPage extends Page {
	public function go():void {
		$this->redirectToClient(
			$this->input->getString("continue")
		);
	}

	private function redirectToClient(string $base64Redirect = null):void {
		if(is_null($base64Redirect)
		|| ($redirectUri = base64_decode($base64Redirect)) === false) {
			$this->redirect("/");
			exit;
		}

		$this->document->bindKeyValue(
			"redirectUri",
			$redirectUri
		);
	}
}