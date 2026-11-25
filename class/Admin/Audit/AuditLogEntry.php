<?php
namespace Authwave\Admin\Audit;

use Authwave\Security\Action;
use DateTimeImmutable;

readonly class AuditLogEntry {
	public function __construct(
		public string $id,
		public Action $action,
		public DateTimeImmutable $createdAt,
		public ?string $actor,
		public string $deployment,
	) {}
}
