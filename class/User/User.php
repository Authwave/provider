<?php
namespace Authwave\User;

use Authwave\Application\ApplicationDeployment;
use DateTime;

class User {
	private int $id;
	private string $uuid;
	private ApplicationDeployment $deployment;
	private string $email;
	private ?DateTime $lastLoggedIn;

	public function __construct(
		ApplicationDeployment $deployment,
		int $id,
		string $uuid,
		string $email,
		DateTime $lastLoggedIn = null
	) {
		$this->id = $id;
		$this->uuid = $uuid;
		$this->deployment = $deployment;
		$this->email = $email;
		$this->lastLoggedIn = $lastLoggedIn;
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

	public function getLastLoggedIn():?DateTime {
		return $this->lastLoggedIn;
	}
}