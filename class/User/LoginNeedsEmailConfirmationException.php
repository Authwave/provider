<?php
namespace Authwave\User;

use Authwave\AuthwaveException;

class LoginNeedsEmailConfirmationException extends AuthwaveException {
	private int $id;

	public function setId(int $id):void {
		$this->id = $id;
	}

	public function getId():int {
		return $this->id;
	}
}