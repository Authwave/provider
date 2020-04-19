<?php
namespace Authwave\Page\Login;

use Authwave\Application\ApplicationDeployment;
use Authwave\Application\ApplicationRepository;
use Authwave\User\User;
use Authwave\User\UserRepository;
use Gt\WebEngine\Logic\Page;

class SuccessPage extends Page {
	public ApplicationRepository $appRepo;
	public UserRepository $userRepo;
	public ApplicationDeployment $deployment;
	public User $user;

	public function go():void {
		if($this->userRepo->doesUserNeedSignupFields(
			$this->user,
			$this->appRepo->getApplicationFields($this->deployment->getApplication())
		)) {
			$this->redirect(
				"/login/signup?continue="
				. $this->input->getString("continue")
			);
			exit;
		}

		$this->redirectToClient(
			$this->input->getString("continue")
		);
	}

	private function redirectToClient(string $base64Redirect = null):void {
		$this->userRepo->setLastLogin($this->user);

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