<?php
namespace Authwave\User;

use Authwave\Model\ApplicationDeployment;

class User {
	public function __construct(
		public readonly string $id,
		public readonly ApplicationDeployment $deployment,
		public readonly string $email,
	) {}
}
