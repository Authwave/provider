<?php
namespace Authwave\Model;

use DateTimeImmutable;

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
		public ?DateTimeImmutable $createdAt = null,
		public ?int $clientNotificationStatus = null,
		public ?bool $clientNotificationAcknowledged = null,
		public ?string $clientNotificationAction = null,
	) {
		$this->applicationDeploymentId = $deployment->id;
	}
}
