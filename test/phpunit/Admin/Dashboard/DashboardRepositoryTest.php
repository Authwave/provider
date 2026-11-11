<?php
namespace Authwave\Test\Admin\Dashboard;

use Authwave\Admin\Dashboard\{DashboardRepository, ReportingPeriod};
use Authwave\Model\{User, Application, ApplicationDeployment};
use DateTimeImmutable;
use GT\Database\Query\QueryCollection;
use GT\Database\Result\{ResultSet, Row};
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DashboardRepositoryTest extends TestCase {
	public function testSeries_fillsMissingDaysWithoutInventingActivity():void {
		$rows = $this->createStub(ResultSet::class);
		$index = 0;
		$rows->method("rewind")->willReturnCallback(function()use(&$index):void {
			$index = 0;
		});
		$rows->method("valid")->willReturnCallback(function()use(&$index):bool {
			return $index === 0;
		});
		$rows->method("current")->willReturn(new Row(["bucket" => "2026-11-09", "logins" => "2", "failures" => "1"]));
		$rows->method("next")->willReturnCallback(function()use(&$index):void {
			$index++;
		});
		$queries = $this->createStub(QueryCollection::class);
		$queries->method("fetchAll")->willReturn($rows);
		$sut = new DashboardRepository($queries);
		$period = new ReportingPeriod("custom", "2026-11-08", "2026-11-10",
			new DateTimeImmutable("2026-11-10"));

		$days = $sut->series($this->actor(), "app", $period);

		self::assertCount(3, $days);
		self::assertSame(0, $days[0]->getInt("logins"));
		self::assertSame(2, $days[1]->getInt("logins"));
		self::assertSame(1, $days[1]->getInt("failures"));
		self::assertSame(0, $days[2]->getInt("failures"));
	}

	public function testSeries_emptySingleDayHas24ZeroHours():void {
		$rows = $this->createStub(ResultSet::class);
		$rows->method("valid")->willReturn(false);
		$queries = $this->createStub(QueryCollection::class);
		$queries->method("fetchAll")->willReturn($rows);
		$sut = new DashboardRepository($queries);
		$period = new ReportingPeriod("24h", now: new DateTimeImmutable("2026-11-10 14:30:00"));

		$series = $sut->series($this->actor(), "app", $period);

		self::assertCount(24, $series);
		self::assertSame("00:00", $series[0]->getString("bucket"));
		self::assertSame("23:00", $series[23]->getString("bucket"));
		self::assertSame(0, array_sum(array_map(fn($row) => $row->getInt("logins"), $series)));
		self::assertSame(0, array_sum(array_map(fn($row) => $row->getInt("failures"), $series)));
	}

	#[DataProvider("invalidActivity")]
	public function testActivity_rejectsInvalidFiltersBeforeQuerying(string $action, int $page):void {
		$queries = $this->createMock(QueryCollection::class);
		$queries->expects(self::never())->method("fetchAll");
		$sut = new DashboardRepository($queries);

		$this->expectException(InvalidArgumentException::class);
		$sut->activity($this->actor(), "app", new ReportingPeriod(), $action, $page);
	}

	private function actor():User {
		return new User("actor", new ApplicationDeployment("deployment", new Application("app", "App", null),
			"Development", "secret", "https://client.test", "/"), "admin@example.test");
	}

	public static function invalidActivity():array {
		return [["REQUEST_RECEIVED", 1], ["", 0], ["", -1], ["", PHP_INT_MAX]];
	}
}
