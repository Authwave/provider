<?php
namespace Authwave\Test\Integration\Security;

use GT\Database\Database;
use Authwave\Security\{Audit, Action, AnonUser};
use GT\Database\Connection\Settings;
use PHPUnit\Framework\TestCase;

class AuditTest extends TestCase {
	public function testCreate_eventsStoreOnlyTheirUsefulContextAndActor():void {
		$database = new Database(new Settings("query", "sqlite", ":memory:"));
		$database->executeSql("create table Audit (id text primary key, action text, detail text, userId text, anonUserId text, createdAt text)");
		$sut = new Audit($database->queryCollection("audit"));
		foreach(Action::cases() as $action) {
			$sut->create($action, ["reason" => "test", "password" => "never-store"], new AnonUser("anonymous"));
		}
		$rows = $database->executeSql("select * from Audit");
		$count = 0;
		foreach($rows as $row) {
			$count++;
			self::assertNotEmpty($row->getString("anonUserId"));
			self::assertNotEmpty($row->getString("createdAt"));
			$detail = json_decode($row->getString("detail"), flags: JSON_THROW_ON_ERROR);
			self::assertEquals((object)["reason" => "test"], $detail);
		}
		self::assertSame(count(Action::cases()), $count);
	}
}
