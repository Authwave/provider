<?php
namespace Authwave\Model;

readonly class UserAdmin {
	public function __construct(
		public string $userId,
		public string $applicationId,
	) {}
}
