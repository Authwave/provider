<?php
namespace Authwave\Test\Model;

use Authwave\Model\UserSession;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserSessionTest extends TestCase {
	#[DataProvider("sessionTimes")]
	public function testIsActive_enforcesIdleAbsoluteAndRevocationBoundaries(string $now, bool $ended, bool $expected):void {
		$sut = new UserSession("session", "user", new DateTimeImmutable("2026-01-01T12:00:00Z"),
			new DateTimeImmutable("2026-01-01T12:30:00Z"),
			$ended ? new DateTimeImmutable("2026-01-01T12:01:00Z") : null,
			new DateTimeImmutable("2026-01-01T12:10:00Z"), 15, true);

		self::assertSame($expected, $sut->isActive(new DateTimeImmutable($now)));
	}

	public static function sessionTimes():iterable {
		yield ["2026-01-01T12:24:59Z", false, true];
		yield ["2026-01-01T12:25:00Z", false, false];
		yield ["2026-01-01T12:30:00Z", false, false];
		yield ["2026-01-01T12:12:00Z", true, false];
	}
}
