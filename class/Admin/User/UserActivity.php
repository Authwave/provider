<?php
namespace Authwave\Admin\User;

use Authwave\Model\User;
use DateTimeImmutable;

readonly class UserActivity {
	public function __construct(
		public User $user,
		public int $loginCount,
		public ?DateTimeImmutable $lastLoginAt,
		public ?DateTimeImmutable $lastFailureAt,
	) {}
}
