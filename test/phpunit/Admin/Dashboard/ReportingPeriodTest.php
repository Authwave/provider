<?php
namespace Authwave\Test\Admin\Dashboard;

use Authwave\Admin\Dashboard\ReportingPeriod;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReportingPeriodTest extends TestCase {
	#[DataProvider("periods")]
	public function testConstruct_calendarDaysAndPreviousPeriod(string $period, string $start, int $days):void {
		$sut = new ReportingPeriod($period, now: new DateTimeImmutable("2026-11-10 23:00:00-05:00"));

		self::assertSame($start, $sut->start->format("Y-m-d H:i:s"));
		self::assertSame("2026-11-12 00:00:00", $sut->end->format("Y-m-d H:i:s"));
		self::assertSame($days, $sut->days);
		self::assertEquals($sut->start, $sut->previousStart->modify("+$days days"));
	}

	public static function periods():array {
		return [["24h", "2026-11-11 00:00:00", 1], ["7d", "2026-11-05 00:00:00", 7],
			["30d", "2026-10-13 00:00:00", 30]];
	}

	public function testConstruct_customIncludesLastDayAndLeapDay():void {
		$sut = new ReportingPeriod("custom", "2024-02-28", "2024-03-01");

		self::assertSame(3, $sut->days);
		self::assertSame("2024-03-02", $sut->end->format("Y-m-d"));
		self::assertSame("2024-02-25", $sut->previousStart->format("Y-m-d"));
	}

	#[DataProvider("invalidPeriods")]
	public function testConstruct_invalidInput(string $period, ?string $from, ?string $to):void {
		$this->expectException(InvalidArgumentException::class);
		$sut = new ReportingPeriod($period, $from, $to, new DateTimeImmutable("2026-11-10"));
	}

	public static function invalidPeriods():array {
		return [["week", null, null], ["custom", null, null],
			["custom", "2026-02-30", "2026-03-01"], ["custom", "2026-11-0\0", "2026-11-10"], ["custom", "2026-1-1", "2026-01-02"],
			["custom", "2026-11-10", "2026-11-09"], ["custom", "2026-11-10", "2026-11-11"],
			["custom", "2025-01-01", "2026-11-10"]];
	}
}
