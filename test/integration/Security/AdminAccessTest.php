<?php

namespace Authwave\Test\Integration\Security;

use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\Security\AdminAccess;
use Authwave\Model\User;
use GT\Database\Query\QueryCollection;
use GT\Database\Result\Row;
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
		$this->pdo->exec("create table User (id char(64) primary key)");
		$this->pdo->exec("create table Application (id char(64) primary key)");
		$this->pdo->exec("insert into User values ('user'), ('other-user')");
		$this->pdo->exec("insert into Application values ('app'), ('other-app')");
		$this->pdo->exec(file_get_contents("$root/query/_migration/015-user-admin.sql"));
		$this->db = $this->createStub(QueryCollection::class);
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

	public static function globalOverrides():array {
		return [
			[" ADMIN@example.test "],
			["other@example.test, ADMIN@example.test"],
			[" , , other@example.test, ADMIN@example.test, third@example.test, "],
		];
	}

	public function testExecuteSql_duplicateGrantIsRejected():void {
		$this->pdo->exec("insert into UserAdmin values ('user', 'app')");
		$this->expectException(PDOException::class);
		$this->pdo->exec("insert into UserAdmin values ('user', 'app')");
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("missingReferences")]
	public function testExecuteSql_grantRequiresExistingReferences(string $userId, string $appId):void {
		$this->expectException(PDOException::class);
		$this->pdo->prepare("insert into UserAdmin values (?, ?)")->execute([$userId, $appId]);
	}

	public static function missingReferences():array {
		return [["missing", "app"], ["user", "missing"]];
	}

	public function testExecuteSql_deletingUserOrApplicationRemovesGrants():void {
		$this->pdo->exec("insert into UserAdmin values ('user', 'app'), ('other-user', 'app'), ('other-user', 'other-app')");
		$this->pdo->exec("delete from User where id = 'user'");
		self::assertSame(2, (int)$this->pdo->query("select count(*) from UserAdmin")->fetchColumn());
		$this->pdo->exec("delete from Application where id = 'app'");
		self::assertSame([["userId" => "other-user", "applicationId" => "other-app"]], $this->pdo->query("select * from UserAdmin")->fetchAll(PDO::FETCH_ASSOC));
	}
	public function testAllows_grantsAreScopedToUserAndApplication():void {
		$sut = new AdminAccess($this->db);
		self::assertFalse($sut->allows($this->user()));
		$this->pdo->exec("insert into UserAdmin values ('user', 'app')");
		self::assertTrue($sut->allows($this->user()));
		self::assertTrue($sut->allows($this->user(deploymentId: "second-deployment")));
		self::assertFalse($sut->allows($this->user(appId: "other-app")));
		self::assertFalse($sut->allows($this->user(id: "other-user")));
		$this->pdo->exec("delete from UserAdmin");
		self::assertFalse($sut->allows($this->user()));
	}
}
