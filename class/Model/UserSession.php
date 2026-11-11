<?php
namespace Authwave\Model;

use DateTimeImmutable;

readonly class UserSession {
	public function __construct(
		public string $id,
		public string $userId,
		public DateTimeImmutable $createdAt,
		public DateTimeImmutable $expiresAt,
		public ?DateTimeImmutable $endedAt = null,
	) {}
}
