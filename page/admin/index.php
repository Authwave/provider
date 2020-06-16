<?php
namespace Authwave\Page\Admin;

use Authwave\Application\ApplicationDeployment;
use Authwave\DataTransfer\AdminRequestData;
use Authwave\DataTransfer\RequestData;
use Authwave\User\AdminUser;
use Authwave\User\User;
use Gt\WebEngine\Logic\Page;

class IndexPage extends Page {
	public User $user;
	public ApplicationDeployment $deployment;

	public function go():void {
		if(!$this->user instanceof AdminUser) {
			$this->redirect($this->deployment->getClientHost());
		}

		$this->document->bindKeyValue("email", $this->user->getEmail());
	}
}