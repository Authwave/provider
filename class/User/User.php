<?php
namespace Authwave\User;

use Authwave\Application\ApplicationDeployment;
use DateTime;
use StdClass;

class User {
	private int $id;
	private string $uuid;
	private ApplicationDeployment $deployment;
	private string $email;
	private ?DateTime $lastLoggedIn;
	private object $fields;

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
		$this->fields = new StdClass();
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

	public function addField(string $key, string $value):void {
		$this->fields->{$key} = $value;
	}

	public function getFields():object {
		return $this->fields;
	}
}