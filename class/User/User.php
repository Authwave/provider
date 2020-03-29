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

	public function getId():int {
		return $this->id;
	}

	public function getUuid():string {
		return $this->uuid;
	}

	public function getDeployment():ApplicationDeployment {
		return $this->deployment;
	}

	public function getEmail():string {
		return $this->email;
	}
}