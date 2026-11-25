<?php
namespace Authwave\Admin\Dashboard;

readonly class DashboardSeriesPoint {
	public function __construct(
		public string $bucket,
		public int $logins,
		public int $failures,
	) {}
}
