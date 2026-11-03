<?php
namespace Authwave\Model;

use DateTimeImmutable;

readonly class UserAuthCode {
	public function __construct(
		public string $id,
		public string $userId,
		public string $code,
		public DateTimeImmutable $createdAt,
		public ?string $hash,
	) {}
}
