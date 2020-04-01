<?php
namespace Authwave\Page;

use Authwave\Application\ApplicationDeployment;
use Gt\Http\Uri;
use Gt\WebEngine\Logic\Page;
use Psr\Http\Message\UriInterface;

class LogoutPage extends Page {
	public ApplicationDeployment $deployment;

	public function go():void {
		$this->session->kill();

		$redirectUri = $this->deployment->getClientHost();
		if($redirectUri->getHost() !== "localhost") {
			$redirectUri = $redirectUri->withScheme("https");
		}

		$returnTo = $this->input->getString("returnTo");
		if($returnTo) {
			$redirectUri = $redirectUri->withPath($returnTo);
		}

		$this->redirect($redirectUri);
		exit;
	}
}