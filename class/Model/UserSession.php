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
		public ?DateTimeImmutable $lastActivityAt = null,
		public ?int $idleTimeoutMinutes = null,
		public bool $managed = false,
	) {}
	public function isActive(DateTimeImmutable $now):bool {
		if($this->endedAt !== null || $this->expiresAt <= $now) {
			return false;
		}

		return !$this->managed || ($this->lastActivityAt !== null
			&& $this->idleTimeoutMinutes !== null
			&& $this->lastActivityAt->modify("+{$this->idleTimeoutMinutes} minutes") > $now);
	}
}
