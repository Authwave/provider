<?php
namespace Authwave\Admin\Dashboard;

use Authwave\Security\Action;
use DateTimeImmutable;

readonly class DashboardActivityEvent {
	public function __construct(
		public string $id,
		public Action $action,
		public DateTimeImmutable $createdAt,
		public string $userId,
		public string $email,
		public string $deploymentId,
		public string $deploymentTitle,
	) {}
}
