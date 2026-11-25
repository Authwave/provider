<?php
namespace Authwave\Admin\Dashboard;

use DateTimeImmutable;

readonly class DashboardNewUser {
	public function __construct(
		public string $userId,
		public string $email,
		public DateTimeImmutable $createdAt,
		public string $title,
	) {}
}
