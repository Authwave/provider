<?php
namespace Authwave\Model;

readonly class User {
	public string $applicationDeploymentId;

	/** Retains the public id field and named parameter contract.
	* @SuppressWarnings("PHPMD.ShortVariable")
	*/
	public function __construct(
		public string $id,
		public ApplicationDeployment $deployment,
		public string $email,
		public ?string $hash = null,
	) {
		$this->applicationDeploymentId = $deployment->id;
	}
}
