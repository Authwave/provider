<?php

namespace Authwave\Test\Integration;

use GT\Database\Result\Row;
use PDO;
use PHPUnit\Framework\TestCase;
use GT\Database\Database;
use GT\Database\Connection\Settings;

class DatabaseTest extends TestCase {

	public static function validCounts():iterable {
		yield ["0"];
		yield ["1"];
		yield ["200"];
	}

	public static function invalidCounts():iterable {
		yield [null];
		foreach([null, "", "abc", "-1", "1.5", "999999999999999999999999"] as $value) {
			yield [new Row(["applicationCount" => $value])];
		}
	}

	public function testCountFor_sqlCountsOnlyTheRequestedAdministratorWithoutCountingTheLoginApp():void {
		$database = new PDO("sqlite::memory:");
		$database->exec("create table UserAdmin (userId text, applicationId text)");
		$database->exec("insert into UserAdmin values ('other', 'other-app'), ('admin', 'first')");
		$query = $database->prepare(file_get_contents("query/user_admin/countApplications.sql"));
		foreach(["admin" => 1, "other" => 1, "new-admin" => 0, "' OR 1=1 --" => 0] as $identity => $count) {
			$query->execute([$identity]);
			self::assertSame($count, (int)$query->fetchColumn());
		}
	}

	public function testGetDeploymentByProviderHost_hostIdQueriesReturnOneRowAndPreferProduction():void {
		$database = new Database(new Settings("query", "sqlite", ":memory:"));
		$database->executeSql("create table Application (id text, productionApplicationDeploymentId text)");
		$database->executeSql("create table ApplicationDeployment (id text, applicationId text, providerHost text, clientHost text)");
		$database->executeSql("insert into Application values ('app', 'production')");
		$database->executeSql("insert into ApplicationDeployment values ('development', 'app', 'login.test', 'client.test'), ('production', 'app', 'login.test', 'client.test')");
		$queries = $database->queryCollection("application_deployment");

		self::assertSame("production", $queries->fetchString("getIdByProviderHost", "login.test"));
		self::assertCount(1, iterator_to_array($queries->fetchAll("getIdByProviderHost", "login.test")));
		self::assertCount(1, iterator_to_array($queries->fetchAll("getIdByClientHost", "client.test")));
		self::assertNull($queries->fetchString("getIdByProviderHost", "absent.test"));
		self::assertNull($queries->fetchString("getIdByClientHost", "absent.test"));
	}

	public static function hostLookups():iterable {
		foreach(["ProviderHost", "ClientHost"] as $field) {
			foreach(["deployment", null] as $identifier) {
				yield ["getDeploymentBy$field", "getIdBy$field", $identifier];
			}
		}
	}
}
