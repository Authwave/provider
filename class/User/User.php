<?php
namespace Authwave\User;

use Authwave\Model\ApplicationDeployment;

class User {
	/** Retains the public id field and named parameter contract.
	* @SuppressWarnings("PHPMD.ShortVariable")
	*/
	public function __construct(
		public readonly string $id,
		public readonly ApplicationDeployment $deployment,
		public readonly string $email,
	) {}
}
