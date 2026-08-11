<?php
namespace Authwave\Security;

class AnonUser {
	public readonly string $id;

	public function __construct(string $id) {
		$this->id = substr($id, -8);
	}
}
