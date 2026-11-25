<?php
namespace Authwave\Test\Integration\Admin\Audit;

use Authwave\Admin\Audit\{AuditRepository, AuditLogEntry};
use Authwave\Admin\Dashboard\ReportingPeriod;
use Authwave\Model\{Application, ApplicationDeployment, User};
use Authwave\Security\Action;
use DateTimeImmutable;
use GT\Database\Connection\Settings;
use GT\Database\Database;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AuditRepositoryTest extends TestCase {
	private function database():Database {
		$database = new Database(new Settings("query", "sqlite", ":memory:"));

		foreach(explode(";", file_get_contents("test/behat/database/schema/001-schema.sql")) as $sql) {
			if(trim($sql) !== "") {
				$database->executeSql($sql);
			}
		}

		$database->executeSql("insert into Application (id,name) values ('app','App'), ('other','Other')");
		$database->executeSql("insert into ApplicationDeployment (id,applicationId,title,secret,providerHost,clientHost,clientLoginPath) values ('dev','app','Development','secret','login.test','https://client.test','/'), ('prod','app','Production','secret','prod.test','https://client.test','/'), ('private','other','Private','secret','private.test','https://private.test','/')");
		$database->executeSql("insert into User (id,applicationDeploymentId,email) values ('admin','private','admin@example.test'), ('alice','dev','alice@example.test'), ('bob','prod','bob@example.test')");
		$database->executeSql("insert into UserAdmin values ('admin','app')");
		$database->executeSql("insert into Audit (id,userId,action,detail,createdAt) values
			('login','alice','LOGIN_COMPLETED',null,'2026-11-10 12:00:00'),
			('policy','admin','SESSION_POLICY_UPDATED','{\"applicationId\":\"app\"}','2026-11-10 12:01:00'),
			('revoked','admin','SESSION_REVOKED','{\"applicationId\":\"app\",\"deploymentId\":\"prod\"}','2026-11-10 12:02:00'),
			('deleted',null,'DEPLOYMENT_DELETED','{\"applicationId\":\"app\",\"from\":{\"id\":\"removed\"}}','2026-11-10 12:03:00'),
			('created','admin','APPLICATION_CREATED','{\"to\":{\"applicationId\":\"app\"}}','2026-11-10 12:04:00'),
			('user-deleted','admin','USER_DELETED','{\"from\":{\"deploymentId\":\"dev\"}}','2026-11-10 12:05:00'),
			('private','alice','APPLICATION_UPDATED','{\"applicationId\":\"other\"}','2026-11-10 12:06:00'),
			('old','alice','LOGIN_COMPLETED',null,'2026-11-09 23:59:59'),
			('end','alice','LOGIN_COMPLETED',null,'2026-11-11 00:00:00'),
			('anonymous',null,'REQUEST_RECEIVED',null,'2026-11-10 12:00:00')");
		return $database;
	}

	private function actor():User {
		return new User("admin", new ApplicationDeployment("private", new Application("other", "Other", null),
			"Private", "secret", "https://client.test", "/"), "admin@example.test");
	}

	private function period():ReportingPeriod {
		return new ReportingPeriod("custom", "2026-11-10", "2026-11-10", new DateTimeImmutable("2026-11-10"));
	}

	public function testLog_scopesRecordedTargetsInsteadOfTheAdministratorsOwnDeployment():void {
		$sut = new AuditRepository($this->database()->queryCollection("audit"));

		$log = $sut->log($this->actor(), "app", $this->period());
		$entries = iterator_to_array($log);

		self::assertSame(["user-deleted", "created", "deleted", "revoked", "policy", "login"],
			array_map(fn(AuditLogEntry $entry) => $entry->id, $entries));
		self::assertSame(Action::USER_DELETED, $entries[0]->action);
		self::assertSame("Development", $entries[0]->deployment);
		self::assertSame("Application-wide", $entries[1]->deployment);
		self::assertSame("Removed deployment", $entries[2]->deployment);
		self::assertNull($entries[2]->actor);
		self::assertSame("admin@example.test", $entries[3]->actor);
		self::assertSame("UTC", $entries[3]->createdAt->getTimezone()->getName());
		self::assertCount(0, $sut->log($this->actor(), "other", $this->period()));
	}

	public function testLog_filtersDeploymentAndAction():void {
		$sut = new AuditRepository($this->database()->queryCollection("audit"));

		$log = $sut->log($this->actor(), "app", $this->period(), "SESSION_REVOKED", deploymentId: "prod");

		self::assertCount(1, $log);
		self::assertSame("revoked", iterator_to_array($log)[0]->id);
		self::assertCount(0, $sut->log($this->actor(), "app", $this->period(), "SESSION_REVOKED", deploymentId: "dev"));
	}

	public function testLog_paginatesInStableOrderAndSupportsEmptyLaterPages():void {
		$database = $this->database();

		for($index = 0; $index < 27; $index++) {
			$database->executeSql("insert into Audit (id,userId,action,createdAt) values (?, 'alice', 'PASSWORD_CHANGED', '2026-11-10 13:00:00')", [sprintf("page-%02d", $index)]);
		}

		$sut = new AuditRepository($database->queryCollection("audit"));
		$first = $sut->log($this->actor(), "app", $this->period(), "PASSWORD_CHANGED");
		$second = $sut->log($this->actor(), "app", $this->period(), "PASSWORD_CHANGED", 2);
		$empty = $sut->log($this->actor(), "app", $this->period(), "PASSWORD_CHANGED", 3);

		self::assertCount(25, $first);
		self::assertFalse($first->hasPrevious());
		self::assertTrue($first->hasNext);
		self::assertCount(2, $second);
		self::assertSame("page-01", iterator_to_array($second)[0]->id);
		self::assertTrue($second->hasPrevious());
		self::assertFalse($second->hasNext);
		self::assertCount(0, $empty);
		self::assertTrue($empty->hasPrevious());
		self::assertFalse($empty->hasNext);
	}

	public function testLog_rejectsUnknownAction():void {
		$sut = new AuditRepository($this->database()->queryCollection("audit"));
		$this->expectException(InvalidArgumentException::class);
		$sut->log($this->actor(), "app", $this->period(), "NOT_AN_EVENT");
	}

	public function testLog_rejectsInvalidPage():void {
		$sut = new AuditRepository($this->database()->queryCollection("audit"));
		$this->expectException(InvalidArgumentException::class);
		$sut->log($this->actor(), "app", $this->period(), page: PHP_INT_MAX);
	}
}
