<?php
namespace Authwave\Model;

use DateTimeImmutable;
use stdClass;

readonly class Audit {
	public function __construct(
		public string $id,
		public string $action,
		public ?stdClass $detail,
		public ?string $userId,
		public ?string $anonUserId,
		public DateTimeImmutable $createdAt,
	) {}
}
