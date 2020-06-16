<?php
namespace Authwave\Page\Profile;

use Authwave\Application\ApplicationDeployment;
use Authwave\User\User;
use Gt\WebEngine\Logic\Page;

class IndexPage extends Page {
	public User $user;
	public ApplicationDeployment $deployment;

	public function go():void {

	}
}