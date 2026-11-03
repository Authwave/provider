<?php
namespace Authwave\Test\Security;

use Authwave\Security\AdminAccess;
use Authwave\Model\User;
use Authwave\Model\{Application, ApplicationDeployment};
use Gt\Database\Query\QueryCollection;
use Gt\Database\Result\Row;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminAccessTest extends TestCase {
	private function user():User {
		$application = new Application("app", "Test", "sender@example.test");
		$deployment = new ApplicationDeployment("deployment", $application, "Test", "secret", "client.test", "/");
		return new User("user", $deployment, "admin@example.test");
	}

	#[DataProvider("grants")]
	public function testAllows_databaseGrant(bool $granted):void {
		$queries = $this->createMock(QueryCollection::class);
		$queries->expects(self::once())->method("fetch")->with("getByUserAndApplication", "user", "app")
			->willReturn($granted ? $this->createStub(Row::class) : null);
		$sut = new AdminAccess($queries);

		self::assertSame($granted, $sut->allows($this->user()));
	}

	public static function grants():iterable {
		yield "assigned" => [true];
		yield "unassigned" => [false];
	}

	#[DataProvider("globalOverrides")]
	public function testAllows_matchingOverrideBypassesDatabase(string $override):void {
		$queries = $this->createMock(QueryCollection::class);
		$queries->expects(self::never())->method("fetch");
		$sut = new AdminAccess($queries, $override);

		self::assertTrue($sut->allows($this->user()));
	}

	public static function globalOverrides():iterable {
		yield [" ADMIN@example.test "];
		yield ["other@example.test, ADMIN@example.test"];
		yield [" , , other@example.test, ADMIN@example.test, third@example.test, "];
	}

	#[DataProvider("nonMatchingOverrides")]
	public function testAllows_nonMatchingOverrideUsesDatabase(?string $override, bool $granted):void {
		$queries = $this->createMock(QueryCollection::class);
		$queries->expects(self::once())->method("fetch")->with("getByUserAndApplication", "user", "app")
			->willReturn($granted ? $this->createStub(Row::class) : null);
		$sut = new AdminAccess($queries, $override);

		self::assertSame($granted, $sut->allows($this->user()));
	}

	public static function nonMatchingOverrides():iterable {
		foreach([null, "", " , , ", "other@example.test, third@example.test",
			"other@example.test; admin@example.test", "prefix-admin@example.test, admin@example.test.invalid",
			"global@example.test", "global@example.test, other@example.test, third@example.test"] as $override) {
			yield [$override, true];
			yield [$override, false];
		}
	}

	public function testAllows_missingUser():void {
		$queries = $this->createMock(QueryCollection::class);
		$queries->expects(self::never())->method("fetch");
		$sut = new AdminAccess($queries, "admin@example.test");

		self::assertFalse($sut->allows(null));
	}
}
