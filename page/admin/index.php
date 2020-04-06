<?php
namespace Authwave\Page\Admin;

use Authwave\DataTransfer\AdminRequestData;
use Authwave\DataTransfer\RequestData;
use Authwave\User\User;
use Gt\WebEngine\Logic\Page;

class IndexPage extends Page {
	public User $user;

	public function go():void {
		if(!isset($this->user)) {
			$this->session->set(
				RequestData::SESSION_REQUEST_DATA,
				new AdminRequestData()
			);
			$this->redirect("/login");
		}
	}
}