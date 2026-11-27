<?php
namespace Authwave\Test\Integration\Email;

use Authwave\Email\EmailRepository;
use Authwave\Admin\Dashboard\ReportingPeriod;
use Authwave\Model\{Application, ApplicationDeployment, User, EmailSettings};
use Authwave\Security\Audit;
use DateTimeImmutable;
use GT\Database\Connection\Settings;
use GT\Database\Database;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EmailLogTest extends TestCase {
	private function database():Database {
		$db = new Database(new Settings("query", "sqlite", ":memory:"));
		foreach(explode(";", file_get_contents("test/behat/database/schema/001-schema.sql")) as $sql) {
			if(trim($sql) !== "") {
				$db->executeSql($sql);
			}
		}
		$db->executeSql("insert into Application (id,name) values ('app','App'),('other','Other')");
		$db->executeSql("insert into ApplicationDeployment (id,applicationId,title,secret,providerHost,clientHost,clientLoginPath) values ('dev','app','Development','s','dev.test','client.test','/'),('prod','app','Production','s','prod.test','client.test','/'),('private','other','Private','s','private.test','client.test','/')");
		$db->executeSql("insert into UserAdmin values ('admin','app')");
		$db->executeSql("insert into Email (id,deploymentId,toEmail,createdAt,scheduledToSendAt,sentAt,deliveryFailedAt) values
			('pending','dev','alice@example.test','2026-11-10 00:00:00','2026-12-01 12:00:00',null,null),
			('sent','prod','bob@example.test','2026-11-10 12:00:00','2026-11-10 12:00:00','2026-11-10 12:01:00',null),
			('error','dev','alice@example.test','2026-11-10 23:59:59','2026-11-10 23:59:59',null,'2026-11-11 00:00:00'),
			('private','private','alice@example.test','2026-11-10 12:00:00',null,null,null),
			('old','dev','alice@example.test','2026-11-09 23:59:59',null,null,null),
			('end','dev','alice@example.test','2026-11-11 00:00:00',null,null,null)");
		$db->executeSql("update Email set subject = case when id = 'sent' then 'Welcome to Authwave' else 'Your login code 100%_ready' end");
		return $db;
	}

	private function repository(Database $db):EmailRepository {
		return new EmailRepository($db->queryCollection("email"), new EmailSettings("localhost", 1025, "", ""), $this->createStub(Audit::class));
	}

	private function actor():User {
		return new User("admin", new ApplicationDeployment("private", new Application("other", "Other", null),
			"Private", "s", "client.test", "/"), "admin@example.test");
	}

	private function period():ReportingPeriod {
		return new ReportingPeriod("custom", "2026-11-10", "2026-11-10", new DateTimeImmutable("2026-11-10"));
	}

	public function testScopeDatesFiltersAndFutureScheduledEmails():void {
		$sut = $this->repository($this->database());
		$entries = iterator_to_array($sut->log($this->actor(), "app", $this->period()));
		self::assertSame(["error", "sent", "pending"], array_column($entries, "id"));
		self::assertSame(["error", "sent", "pending"], array_column($entries, "status"));
		self::assertSame("Your login code 100%_ready", $entries[0]["subject"]);
		self::assertArrayNotHasKey("htmlContent", $entries[0]);
		self::assertCount(0, $sut->log($this->actor(), "other", $this->period()));
		foreach(["pending", "sent", "error"] as $status) {
			$log = $sut->log($this->actor(), "app", $this->period(), status: $status);
			self::assertSame([$status], array_column(iterator_to_array($log), "id"));
		}
		self::assertCount(1, $sut->log($this->actor(), "app", $this->period(), "prod", "bob@example.test", "sent"));
		self::assertCount(0, $sut->log($this->actor(), "app", $this->period(), "dev", "bob@example.test"));
		self::assertCount(0, $sut->log($this->actor(), "app", $this->period(), toEmail: "%"));
	}

	public function testSubjectSearchMatchesPartialTextAndTreatsWildcardsLiterally():void {
		$sut = $this->repository($this->database());
		$log = $sut->log($this->actor(), "app", $this->period(), subject: "WELCOME");
		self::assertSame(["sent"], array_column(iterator_to_array($log), "id"));
		self::assertCount(2, $sut->log($this->actor(), "app", $this->period(), subject: "100%_"));
		self::assertCount(0, $sut->log($this->actor(), "app", $this->period(), status: "pending", subject: "Welcome"));
	}

	public function testPartialEmailAndSubjectFiltersWorkTogether():void {
		$sut = $this->repository($this->database());
		self::assertCount(2, $sut->log($this->actor(), "app", $this->period(), toEmail: "ALICE"));
		self::assertCount(3, $sut->log($this->actor(), "app", $this->period(), toEmail: "@example.test"));
		$log = $sut->log($this->actor(), "app", $this->period(),
			toEmail: "BOB@", subject: "WELCOME");
		self::assertSame(["sent"], array_column(iterator_to_array($log), "id"));
		self::assertCount(0, $sut->log($this->actor(), "app", $this->period(),
			toEmail: "alice", subject: "welcome"));
	}

	public function testPaginationAndRecoveryAfterFailure():void {
		$db = $this->database();
		for($i = 0; $i < 30; $i++) {
			$db->executeSql("insert into Email (id,deploymentId,toEmail,createdAt) values ('page-$i','dev','page@example.test','2026-11-10 12:00:00')");
		}
		$sut = $this->repository($db);
		$first = $sut->log($this->actor(), "app", $this->period());
		$second = $sut->log($this->actor(), "app", $this->period(), page: 2);
		self::assertCount(25, $first);
		self::assertTrue($first->hasNext);
		self::assertFalse($first->hasPrevious());
		self::assertCount(8, $second);
		self::assertFalse($second->hasNext);
		self::assertTrue($second->hasPrevious());
		self::assertSame([], array_intersect(array_column(iterator_to_array($first), "id"), array_column(iterator_to_array($second), "id")));
		self::assertCount(0, $sut->log($this->actor(), "app", $this->period(), page: 3));
		$db->queryCollection("email")->update("markAsSent", ["id" => "error", "sentMessageId" => "message"]);
		self::assertCount(0, $sut->log($this->actor(), "app", $this->period(), status: "error"));
		self::assertCount(2, $sut->log($this->actor(), "app", $this->period(), status: "sent"));
	}

	public function testRejectsInvalidStatus():void {
		$this->expectException(InvalidArgumentException::class);
		$this->repository($this->database())->log($this->actor(), "app", $this->period(), status: "bounced");
	}

	public function testRejectsInvalidPage():void {
		$this->expectException(InvalidArgumentException::class);
		$this->repository($this->database())->log($this->actor(), "app", $this->period(), page: 0);
	}
}
