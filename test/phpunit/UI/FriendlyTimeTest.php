<?php
namespace Authwave\Test\UI;

use Authwave\Admin\FriendlyTime;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class FriendlyTimeTest extends TestCase {
	public function testRelativeTimesAndCalendarDayBoundaries():void {
		$now = new DateTimeImmutable("2026-10-06 14:00:00 Europe/London");
		foreach([
			"2026-10-06 13:59:45" => "Just now",
			"2026-10-06 13:59:00" => "1 minute ago",
			"2026-10-06 13:00:00" => "1 hour ago",
			"2026-10-06 12:00:00" => "2 hours ago",
			"2026-10-05 09:00:00" => "Yesterday morning",
			"2026-10-05 15:00:00" => "Yesterday afternoon",
			"2026-10-05 20:00:00" => "Yesterday evening",
			"2026-10-04 20:00:00" => "2 days ago",
			"2026-09-01 09:00:00" => "1 Sep 2026",
		] as $date => $expected) {
			self::assertSame($expected, FriendlyTime::format(new DateTimeImmutable("$date Europe/London"), $now));
		}
		self::assertSame("Yesterday evening", FriendlyTime::format(
			new DateTimeImmutable("2026-10-05 23:30:00 Europe/London"),
			new DateTimeImmutable("2026-10-06 00:30:00 Europe/London"),
		));
	}
}
