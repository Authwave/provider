<?php
namespace Authwave\Session;

use Gt\Session\SessionStore;

class LoginSessionCreator {
	public function __construct(
		private SessionStore $session
	) {
	}


}
