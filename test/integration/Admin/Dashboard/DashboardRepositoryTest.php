<?php
namespace Authwave\Test\Integration\Admin\Dashboard;

use Authwave\Admin\Dashboard\{DashboardRepository, ReportingPeriod};
use Authwave\Model\{Application, ApplicationDeployment, User};
use DateTimeImmutable;
use GT\Database\Connection\Settings;
use GT\Database\Database;
use PHPUnit\Framework\TestCase;

class DashboardRepositoryTest extends TestCase {
	private function database():Database {
		$database = new Database(new Settings("query", "sqlite", ":memory:"));

		foreach(explode(";", file_get_contents("test/behat/database/schema/001-schema.sql")) as $sql) {
			if(trim($sql) !== "") {
				$database->executeSql($sql);
			}
		}

		$database->executeSql("insert into Application (id,name) values ('app','App'), ('other','Other')");
		$database->executeSql("insert into ApplicationDeployment (id,applicationId,title,secret,providerHost,clientHost,clientLoginPath) values ('dev','app','Development','secret','login.test','https://client.test','/'), ('prod','app','Production','secret','prod.test','https://client.test','/'), ('private','other','Private','secret','private.test','https://private.test','/')");
		$database->executeSql("insert into User (id,applicationDeploymentId,email,createdAt) values ('admin','dev','admin@example.test','2025-01-01'), ('alice','dev','alice@example.test','2026-11-09 00:00:00'), ('bob','prod','bob@example.test','2026-11-10 00:00:00'), ('hidden','private','private@example.test','2026-11-09'), ('legacy','dev','legacy@example.test',null)");
		$database->executeSql("insert into UserAdmin values ('admin','app')");
		$database->executeSql("insert into Audit (id,userId,action,createdAt) values ('one','alice','LOGIN_COMPLETED','2026-11-09 12:00:00'), ('two','alice','LOGIN_COMPLETED','2026-11-10 12:00:00'), ('three','alice','PASSWORD_LOGIN_FAILED','2026-11-10 12:01:00'), ('four','alice','SECURITY_CODE_REJECTED','2026-11-10 12:02:00'), ('five','alice','SECURITY_CODE_REQUESTED','2026-11-10 12:03:00'), ('six','alice','PASSWORD_CHANGED','2026-11-10 12:04:00'), ('other','hidden','LOGIN_COMPLETED','2026-11-10 12:00:00'), ('old','alice','LOGIN_COMPLETED','2026-11-08 23:59:59'), ('end','alice','LOGIN_COMPLETED','2026-11-11 00:00:00'), ('noise','alice','REQUEST_RECEIVED','2026-11-10 12:00:00'), ('anonymous',null,'PASSWORD_LOGIN_FAILED','2026-11-10 12:00:00')");
		return $database;
	}

	private function actor():User {
		return new User("admin", new ApplicationDeployment("dev", new Application("app", "App", null),
			"Development", "secret", "https://client.test", "/"), "admin@example.test");
	}

	private function period():ReportingPeriod {
		return new ReportingPeriod("custom", "2026-11-09", "2026-11-10", new DateTimeImmutable("2026-11-10"));
	}

	public function testTotals_countsOnlyBusinessEventsInOwnedApplicationAndHalfOpenInterval():void {
		$sut = new DashboardRepository($this->database()->queryCollection("dashboard"));
		$period = $this->period();

		$totals = $sut->totals($this->actor(), "app", $period->start, $period->end);

		self::assertSame(2, $totals->getInt("logins"));
		self::assertSame(2, $totals->getInt("failures"));
		self::assertSame(1, $totals->getInt("codes"));
		self::assertSame(1, $totals->getInt("passwords"));
		self::assertSame(0, $sut->totals($this->actor(), "other", $period->start, $period->end)->getInt("logins"));
	}

	public function testSeries_groupsBothDeploymentsAndRetainsZeros():void {
		$sut = new DashboardRepository($this->database()->queryCollection("dashboard"));

		$days = $sut->series($this->actor(), "app", $this->period());

		self::assertCount(2, $days);
		self::assertSame("2026-11-09", $days[0]->getString("bucket"));
		self::assertSame(1, $days[0]->getInt("logins"));
		self::assertSame(2, $days[1]->getInt("failures"));
	}

	public function testSeries_groupsHoursAndExcludesOtherDaysAndApplications():void {
		$database = $this->database();
		$database->executeSql("insert into Audit (id,userId,action,createdAt) values ('midnight','alice','LOGIN_COMPLETED','2026-11-10 00:00:00'), ('hour-end','alice','LOGIN_COMPLETED','2026-11-10 00:59:59'), ('next-hour','alice','LOGIN_COMPLETED','2026-11-10 01:00:00'), ('day-end','alice','LOGIN_COMPLETED','2026-11-10 23:59:59')");
		$sut = new DashboardRepository($database->queryCollection("dashboard"));
		$period = new ReportingPeriod("24h", now: new DateTimeImmutable("2026-11-10 14:30:00"));

		$series = $sut->series($this->actor(), "app", $period);

		self::assertCount(24, $series);
		self::assertSame(2, $series[0]->getInt("logins"));
		self::assertSame(1, $series[1]->getInt("logins"));
		self::assertSame(0, $series[2]->getInt("logins"));
		self::assertSame(1, $series[12]->getInt("logins"));
		self::assertSame(2, $series[12]->getInt("failures"));
		self::assertSame(1, $series[23]->getInt("logins"));
		self::assertSame(5, array_sum(array_map(fn($row) => $row->getInt("logins"), $series)));
		self::assertSame([], array_filter($sut->series($this->actor(), "other", $period),
			fn($row) => $row->getInt("logins") > 0));
	}

	public function testNewUsers_countsRecordsWithKnownCreationDatesRatherThanAuditActors():void {
		$sut = new DashboardRepository($this->database()->queryCollection("dashboard"));
		$period = $this->period();

		self::assertSame(2, $sut->newUserCount($this->actor(), "app", $period->start, $period->end));
		self::assertSame(["bob", "alice"], array_map(fn($row) => $row->getString("userId"),
			$sut->newUsers($this->actor(), "app", $period)));
		self::assertSame([], $sut->newUsers($this->actor(), "other", $period));
	}

	public function testAwaitingFirstLogin_excludesAnyoneWithAnyHistoricalLogin():void {
		$sut = new DashboardRepository($this->database()->queryCollection("dashboard"));

		$rows = $sut->awaitingFirstLogin($this->actor(), "app", $this->period());

		self::assertCount(1, $rows);
		self::assertSame("bob", $rows[0]->getString("userId"));
	}

	public function testTopUsers_ranksSuccessfulLoginsWithinPeriod():void {
		$sut = new DashboardRepository($this->database()->queryCollection("dashboard"));

		$rows = $sut->topUsers($this->actor(), "app", $this->period());

		self::assertCount(1, $rows);
		self::assertSame("alice", $rows[0]->getString("userId"));
		self::assertSame(2, $rows[0]->getInt("logins"));
	}

	public function testActivity_filtersPagesAndSortsDeterministicallyWithoutUnrelatedEvents():void {
		$database = $this->database();

		for($index = 0; $index < 30; $index++) {
			$database->executeSql("insert into Audit (id,userId,action,createdAt) values ('page-$index','alice','LOGIN_COMPLETED','2026-11-10 13:00:00')");
		}

		$sut = new DashboardRepository($database->queryCollection("dashboard"));
		$rows = $sut->activity($this->actor(), "app", $this->period());
		$next = $sut->activity($this->actor(), "app", $this->period(), page: 2);

		self::assertCount(26, $rows);
		self::assertCount(11, $next);
		self::assertNotSame($rows[24]->getString("id"), $next[0]->getString("id"));
		self::assertSame("page-9", $rows[0]->getString("id"));
		self::assertCount(1, $sut->activity($this->actor(), "app", $this->period(), "PASSWORD_LOGIN_FAILED"));
		self::assertSame([], $sut->activity($this->actor(), "other", $this->period()));
	}

	public function testTotals_emptyDataReturnsZero():void {
		$sut = new DashboardRepository($this->database()->queryCollection("dashboard"));
		$period = new ReportingPeriod("custom", "2026-01-01", "2026-01-02");

		self::assertSame(0, $sut->totals($this->actor(), "app", $period->start, $period->end)->getInt("logins"));
		self::assertSame(0, $sut->newUserCount($this->actor(), "app", $period->start, $period->end));
	}
	public function testTotals_deploymentFilterCannotIncludeOtherDeploymentsOrApplications():void {
		$sut = new DashboardRepository($this->database()->queryCollection("dashboard"));
		$period = $this->period();

		self::assertSame(2, $sut->totals($this->actor(), "app", $period->start, $period->end, "dev")->getInt("logins"));
		self::assertSame(0, $sut->totals($this->actor(), "app", $period->start, $period->end, "prod")->getInt("logins"));
		self::assertSame(0, $sut->totals($this->actor(), "app", $period->start, $period->end, "private")->getInt("logins"));
		self::assertSame(0, $sut->totals($this->actor(), "app", $period->start, $period->end, "missing")->getInt("logins"));
	}

	public function testNewUserCount_deploymentFilterRestrictsUserRecords():void {
		$sut = new DashboardRepository($this->database()->queryCollection("dashboard"));
		$period = $this->period();

		self::assertSame(1, $sut->newUserCount($this->actor(), "app", $period->start, $period->end, "prod"));
		self::assertSame(0, $sut->newUserCount($this->actor(), "app", $period->start, $period->end, "private"));
	}

	public function testSeries_deploymentFilterRetainsEmptyBuckets():void {
		$sut = new DashboardRepository($this->database()->queryCollection("dashboard"));

		$series = $sut->series($this->actor(), "app", $this->period(), "prod");

		self::assertCount(2, $series);
		self::assertSame(0, array_sum(array_map(fn($row) => $row->getInt("logins"), $series)));
	}

	public function testActivity_deploymentFilterRestrictsEvents():void {
		$sut = new DashboardRepository($this->database()->queryCollection("dashboard"));

		self::assertSame([], $sut->activity($this->actor(), "app", $this->period(), deploymentId: "prod"));
		self::assertCount(6, $sut->activity($this->actor(), "app", $this->period(), deploymentId: "dev"));
	}

	public function testTopUsers_deploymentFilterRestrictsRanking():void {
		$sut = new DashboardRepository($this->database()->queryCollection("dashboard"));

		self::assertSame([], $sut->topUsers($this->actor(), "app", $this->period(), "prod"));
		self::assertCount(1, $sut->topUsers($this->actor(), "app", $this->period(), "dev"));
	}

	public function testNewUsers_deploymentFilterRestrictsRecords():void {
		$sut = new DashboardRepository($this->database()->queryCollection("dashboard"));

		self::assertSame(["bob"], array_map(fn($row) => $row->getString("userId"),
			$sut->newUsers($this->actor(), "app", $this->period(), "prod")));
		self::assertSame([], $sut->newUsers($this->actor(), "app", $this->period(), "private"));
	}

	public function testAwaitingFirstLogin_deploymentFilterRestrictsRecords():void {
		$sut = new DashboardRepository($this->database()->queryCollection("dashboard"));

		self::assertSame(["bob"], array_map(fn($row) => $row->getString("userId"),
			$sut->awaitingFirstLogin($this->actor(), "app", $this->period(), "prod")));
		self::assertSame([], $sut->awaitingFirstLogin($this->actor(), "app", $this->period(), "dev"));
	}

}
