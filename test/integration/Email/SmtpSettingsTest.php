<?php
namespace Authwave\Test\Integration\Email;

use Authwave\Model\{ApplicationRepository, Application, ApplicationDeployment, User, EmailSettings};
use Authwave\Security\{Audit, Action, DomainVerification};
use GT\Database\Connection\Settings;
use GT\Database\Database;
use GT\Http\ResponseStatusException\ClientError\HttpNotFound;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SmtpSettingsTest extends TestCase {
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
		$db->executeSql("insert into Email (id,deploymentId,scheduledToSendAt) values ('dev-email','dev','2020-01-01'),('prod-email','prod','2020-01-01')");
		return $db;
	}

	private function actor():User {
		return new User("admin", new ApplicationDeployment("private", new Application("other", "Other", null),
			"Private", "s", "client.test", "/"), "admin@example.test");
	}

	private function repository(Database $db):ApplicationRepository {
		$audit = $this->createStub(Audit::class);
		$audit->method("create")->willReturnCallback(function(Action $action, array $detail):void {
			self::assertSame(Action::EMAIL_SETTINGS_UPDATED, $action);
			self::assertArrayNotHasKey("password", $detail);
			self::assertArrayNotHasKey("username", $detail);
			self::assertStringNotContainsString("smtp-password", json_encode($detail));
		});
		return new ApplicationRepository($db, $audit, $this->createStub(DomainVerification::class));
	}

	public function testPersistsApplicationDefaultsDeploymentOverridesAndPasswordRetention():void {
		$db = $this->database();
		$sut = $this->repository($db);
		$actor = $this->actor();
		self::assertNull($sut->emailSettings($actor, "app"));
		$sut->saveEmailSettings($actor, "app", "", new EmailSettings("smtp.app.test", 587, "app-user", "smtp-password"));
		self::assertSame("smtp-password", $sut->emailSettings($actor, "app")->password);
		self::assertNull($sut->emailSettings($actor, "app", "dev"));
		$sut->saveEmailSettings($actor, "app", "dev", new EmailSettings("smtp.dev.test", 2525, "dev-user", "dev-smtp-password"));
		$sut->saveEmailSettings($actor, "app", "dev", new EmailSettings("smtp.changed.test", 465, "dev-user", ""));
		self::assertSame("dev-smtp-password", $sut->emailSettings($actor, "app", "dev")->password);
		$settings = [];
		foreach($db->queryCollection("email")->fetchAll("getScheduled") as $row) {
			$settings[$row->getString("id")] = json_decode($row->getString("emailSettings"), true);
		}
		self::assertSame("smtp.changed.test", $settings["dev-email"]["host"]);
		self::assertSame("smtp.app.test", $settings["prod-email"]["host"]);
		$sut->saveEmailSettings($actor, "app", "dev", null);
		self::assertNull($sut->emailSettings($actor, "app", "dev"));
		foreach($db->queryCollection("email")->fetchAll("getScheduled") as $row) {
			self::assertSame("smtp.app.test", json_decode($row->getString("emailSettings"), true)["host"]);
		}
		$sut->saveEmailSettings($actor, "app", "", null);
		self::assertNull($sut->emailSettings($actor, "app"));
	}

	public function testRejectsAnotherAccountsApplication():void {
		$this->expectException(HttpNotFound::class);
		$this->repository($this->database())->saveEmailSettings($this->actor(), "other", "", null);
	}

	public function testRejectsDeploymentFromAnotherApplication():void {
		$this->expectException(InvalidArgumentException::class);
		$this->repository($this->database())->saveEmailSettings($this->actor(), "app", "private", null);
	}

	public function testRejectsInvalidSmtpDetails():void {
		$this->expectException(InvalidArgumentException::class);
		$this->repository($this->database())->saveEmailSettings($this->actor(), "app", "",
			new EmailSettings("smtp://injected.test", 70000, "user", "password"));
	}
}
