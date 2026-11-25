<?php
namespace Authwave\Admin\Dashboard;

readonly class DashboardTotals {
	public function __construct(
		public int $logins,
		public int $failures,
		public int $codes,
		public int $passwords,
	) {}
}
