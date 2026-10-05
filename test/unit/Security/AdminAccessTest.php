<?php
namespace Authwave\Test\Security;

use Authwave\Model\{Application, ApplicationDeployment};
use Authwave\Security\AdminAccess;
use Authwave\User\User;
use Gt\Database\Query\QueryCollection;
use Gt\Database\Result\Row;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

class AdminAccessTest extends TestCase {
	private PDO $pdo;
	private QueryCollection $db;

	protected function setUp():void {
		$root = dirname(__DIR__, 3);
		$this->pdo = new PDO("sqlite::memory:");
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec("pragma foreign_keys = on");
		$this->pdo->exec("create table user (id char(64) primary key)");
		$this->pdo->exec("create table application (id char(64) primary key)");
		$this->pdo->exec("insert into user values ('user'), ('other-user')");
		$this->pdo->exec("insert into application values ('app'), ('other-app')");
		$this->pdo->exec(file_get_contents("$root/query/_migration/015-user-admin.sql"));
		$this->db = $this->createMock(QueryCollection::class);
		$this->db->method("fetch")->willReturnCallback(function(string $query, string $userId, string $applicationId) use($root):?Row {
			$statement = $this->pdo->prepare(file_get_contents("$root/query/user_admin/$query.sql"));
			$statement->execute([$userId, $applicationId]);
			$row = $statement->fetch(PDO::FETCH_ASSOC);
			return $row ? new Row($row) : null;
		});
	}

	private function user(string $id = "user", string $appId = "app", string $deploymentId = "deployment"):User {
		$app = new Application($appId, "Test app", "sender@example.test");
		$deployment = new ApplicationDeployment($deploymentId, $app, "Test", "secret", "client.example.test", "/");
		return new User($id, $deployment, "admin@example.test");
	}

	public function testGrantsAreScopedToUserAndApplication():void {
		$access = new AdminAccess($this->db);
		self::assertFalse($access->allows($this->user()));
		$this->pdo->exec("insert into user_admin values ('user', 'app')");
		self::assertTrue($access->allows($this->user()));
		self::assertTrue($access->allows($this->user(deploymentId: "second-deployment")));
		self::assertFalse($access->allows($this->user(appId: "other-app")));
		self::assertFalse($access->allows($this->user(id: "other-user")));
		$this->pdo->exec("delete from user_admin");
		self::assertFalse($access->allows($this->user()));
	}

	/** @dataProvider globalOverrides */
	public function testGlobalOverrideDoesNotRequireDatabaseGrant(string $override):void {
		$db = $this->createMock(QueryCollection::class);
		$db->expects(self::never())->method("fetch");
		$access = new AdminAccess($db, $override);
		self::assertTrue($access->allows($this->user()));
		self::assertTrue($access->allows($this->user(appId: "other-app")));
		self::assertFalse($access->allows(null));
	}

	public function globalOverrides():array {
		return [
			[" ADMIN@example.test "],
			["other@example.test, ADMIN@example.test"],
			[" , , other@example.test, ADMIN@example.test, third@example.test, "],
		];
	}

	public function testNonMatchingListsDoNotGrantAccess():void {
		foreach([null, "", " , , ", "other@example.test, third@example.test", "other@example.test; admin@example.test", "prefix-admin@example.test, admin@example.test.invalid"] as $override) {
			self::assertFalse((new AdminAccess($this->db, $override))->allows($this->user()));
		}
	}

	public function testNonMatchingOverrideStillAllowsDatabaseAdmin():void {
		$this->pdo->exec("insert into user_admin values ('user', 'app')");
		foreach([null, "", "global@example.test", "global@example.test, other@example.test, third@example.test"] as $override) {
			self::assertTrue((new AdminAccess($this->db, $override))->allows($this->user()));
		}
	}

	public function testDuplicateGrantIsRejected():void {
		$this->pdo->exec("insert into user_admin values ('user', 'app')");
		$this->expectException(PDOException::class);
		$this->pdo->exec("insert into user_admin values ('user', 'app')");
	}

	/** @dataProvider missingReferences */
	public function testGrantRequiresExistingReferences(string $userId, string $appId):void {
		$this->expectException(PDOException::class);
		$this->pdo->prepare("insert into user_admin values (?, ?)")->execute([$userId, $appId]);
	}

	public function missingReferences():array {
		return [["missing", "app"], ["user", "missing"]];
	}

	public function testDeletingUserOrApplicationRemovesGrants():void {
		$this->pdo->exec("insert into user_admin values ('user', 'app'), ('other-user', 'app'), ('other-user', 'other-app')");
		$this->pdo->exec("delete from user where id = 'user'");
		self::assertSame(2, (int)$this->pdo->query("select count(*) from user_admin")->fetchColumn());
		$this->pdo->exec("delete from application where id = 'app'");
		self::assertSame([["userId" => "other-user", "applicationId" => "other-app"]], $this->pdo->query("select * from user_admin")->fetchAll(PDO::FETCH_ASSOC));
	}
}
