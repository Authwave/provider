<?php
namespace Authwave\Admin\Dashboard;

readonly class DashboardTopUser {
	public function __construct(
		public string $userId,
		public string $email,
		public string $title,
		public int $logins,
	) {}
}
