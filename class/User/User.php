<?php
namespace Authwave\User;

use Authwave\Application\ApplicationDeployment;

class User {
	private int $id;
	private string $uuid;
	private ApplicationDeployment $deployment;
	private string $email;

	public function __construct(
		ApplicationDeployment $deployment,
		int $id,
		string $uuid,
		string $email
	) {
		$this->id = $id;
		$this->uuid = $uuid;
		$this->deployment = $deployment;
		$this->email = $email;
	}
}