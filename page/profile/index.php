<?php
namespace Authwave\Page\Profile;

use Authwave\User\User;
use Gt\WebEngine\Logic\Page;

class IndexPage extends Page {
	public User $user;

	public function go():void {
		if(isset($this->user)) {
			$this->document->getElementById("output")->innerText = "Logged in to client as " . $this->user->getEmail();
		}
		else {
			$this->document->getElementById("output")->innerText = "Not logged in to client";
		}
	}
}