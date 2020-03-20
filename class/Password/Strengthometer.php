<?php
namespace Authwave\Password;

class Strengthometer {
	const MIN_LENGTH = 12;

	private string $password;

	public function __construct(string $password) {
		$this->password = $password;
	}

	public function validate():void {
		if(mb_strlen($this->password) < self::MIN_LENGTH) {
			throw new PasswordTooShortException();
		}
	}
}