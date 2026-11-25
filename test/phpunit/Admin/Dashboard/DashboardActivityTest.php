<?php
namespace Authwave\Test\Admin\Dashboard;

use Authwave\Admin\Dashboard\{DashboardActivity, DashboardActivityEvent};
use Authwave\Security\Action;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class DashboardActivityTest extends TestCase {
	public function testGetIterator_preservesEventsAcrossRepeatedTraversals():void {
		$event = new DashboardActivityEvent("event", Action::LOGIN_COMPLETED,
			new DateTimeImmutable("2026-11-25T12:00:00Z"), "user", "user@example.test",
			"deployment", "Production");
		$sut = new DashboardActivity([$event], 1, false);

		self::assertSame([$event], iterator_to_array($sut));
		self::assertSame([$event], iterator_to_array($sut));
		self::assertCount(1, $sut);
	}
}
