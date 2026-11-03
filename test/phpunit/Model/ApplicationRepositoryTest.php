<?php

namespace Authwave\Test\Model;

use Authwave\Model\ApplicationRepository;
use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\Model\User;
use Gt\Database\Query\QueryCollection;
use Gt\Database\Result\Row;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use Gt\Database\Database;
use Authwave\Security\Audit;
use Gt\Http\Uri;

class ApplicationRepositoryTest extends TestCase {
	private function database(QueryCollection $queries):\Gt\Database\Database {
		$database = $this->createStub(\Gt\Database\Database::class);
		$database->method("queryCollection")->willReturn($queries);
		return $database;
	}

	private function administrator(string $identifier = "admin"):User {
		$application = new Application("provider", "Provider", "sender@example.test");
		$deployment = new ApplicationDeployment("deployment", $application, "Provider", "key", "app.test", "/");
		return new User($identifier, $deployment, "admin@example.test");
	}

	#[DataProvider("validCounts")]
	public function testCountFor_countsAssignedApplications(string $count):void {
		$database = $this->createMock(QueryCollection::class);
		$database->expects(self::once())->method("fetch")->with("countApplications", "admin")
			->willReturn(new Row(["applicationCount" => $count]));
		$sut = new ApplicationRepository($this->database($database),
			$this->createStub(\Authwave\Security\Audit::class));
		self::assertSame((int)$count, $sut->countFor($this->administrator()));
	}

	public static function validCounts():iterable {
		yield ["0"];
		yield ["1"];
		yield ["200"];
	}

	#[DataProvider("invalidCounts")]
	public function testCountFor_invalidOrMissingDatabaseResultsAreRejected(?Row $row):void {
		$database = $this->createStub(QueryCollection::class);
		$database->method("fetch")->willReturn($row);
		$this->expectException(UnexpectedValueException::class);
		$sut = new ApplicationRepository($this->database($database),
			$this->createStub(\Authwave\Security\Audit::class));
		$sut->countFor($this->administrator());
	}

	public static function invalidCounts():iterable {
		yield [null];
		foreach([null, "", "abc", "-1", "1.5", "999999999999999999999999"] as $value) {
			yield [new Row(["applicationCount" => $value])];
		}
	}

	public function testCountFor_missingIdentityDoesNotQueryDatabase():void {
		$database = $this->createMock(QueryCollection::class);
		$database->expects(self::never())->method("fetch");
		$this->expectException(InvalidArgumentException::class);
		$sut = new ApplicationRepository($this->database($database),
			$this->createStub(\Authwave\Security\Audit::class));
		$sut->countFor($this->administrator(" "));
	}

	private function hostQueries(string $query, ?string $identifier):QueryCollection {
		$queries = $this->createMock(QueryCollection::class);
		$queries->expects(self::once())->method("fetchString")->with($query, "example.test")
			->willReturn($identifier);
		$row = $identifier === null ? null : new Row([
			"applicationId" => "app", "name" => "App", "applicationDeploymentId" => $identifier,
			"title" => "Production", "secret" => "secret", "clientHost" => "client.test",
			"clientLoginPath" => "/login/", "providerHost" => "login.test",
		]);
		$queries->expects($identifier === null ? self::never() : self::once())->method("fetch")
			->with("getById", $identifier)->willReturn($row);
		return $queries;
	}

	#[DataProvider("identifiers")]
	public function testGetDeploymentByProviderHost_resolvesCanonicalDeployment(?string $identifier):void {
		$queries = $this->hostQueries("getIdByProviderHost", $identifier);
		$sut = new ApplicationRepository($this->database($queries), $this->createStub(Audit::class));

		$deployment = $sut->getDeploymentByProviderHost("example.test");

		self::assertSame($identifier, $deployment?->id);

		if($deployment !== null) {
			self::assertSame("app", $deployment->applicationId);
		}
	}

	#[DataProvider("identifiers")]
	public function testGetDeploymentByClientHost_resolvesCanonicalDeployment(?string $identifier):void {
		$queries = $this->hostQueries("getIdByClientHost", $identifier);
		$sut = new ApplicationRepository($this->database($queries), $this->createStub(Audit::class));

		$deployment = $sut->getDeploymentByClientHost("example.test");

		self::assertSame($identifier, $deployment?->id);

		if($deployment !== null) {
			self::assertSame("app", $deployment->applicationId);
		}
	}

	#[DataProvider("missingIdentifiers")]
	public function testGetById_missingIdsAndRowsReturnNull(?string $identifier):void {
		$queries = $this->createMock(QueryCollection::class);
		$queries->expects($identifier === null ? self::never() : self::once())->method("fetch")
			->with("getById", $identifier)->willReturn(null);
		$sut = new ApplicationRepository($this->database($queries), $this->createStub(Audit::class));

		self::assertNull($sut->getById($identifier));
	}

	#[DataProvider("missingIdentifiers")]
	public function testGetDeploymentById_missingIdsAndRowsReturnNull(?string $identifier):void {
		$queries = $this->createMock(QueryCollection::class);
		$queries->expects($identifier === null ? self::never() : self::once())->method("fetch")
			->with("getById", $identifier)->willReturn(null);
		$sut = new ApplicationRepository($this->database($queries), $this->createStub(Audit::class));

		self::assertNull($sut->getDeploymentById($identifier));
	}

	public static function identifiers():iterable {
		yield "found" => ["deployment"];
		yield "absent" => [null];
	}

	public static function missingIdentifiers():iterable {
		yield "no ID" => [null];
		yield "missing row" => ["missing"];
	}

	#[DataProvider("clients")]
	public function testGetClientRedirectUri_returnsTheClientLoginUriWithoutSideEffects(string $host, string $path):void {
		$database = $this->createMock(Database::class);
		$database->expects(self::never())->method("queryCollection");
		$audit = $this->createMock(Audit::class);
		$audit->expects(self::never())->method("create");
		$sut = new ApplicationRepository($database, $audit);
		$deployment = new ApplicationDeployment("deployment",
			new Application("app", "App", "sender@example.test"),
			"Production", "secret", $host, $path);

		$uri = $sut->getClientRedirectUri($deployment);

		self::assertInstanceOf(Uri::class, $uri);
		self::assertSame($host . $path, (string)$uri);
	}

	public static function clients():iterable {
		yield ["https://client.example.test", "/login/"];
		yield ["http://localhost:8081", "/authenticate/"];
		yield ["https://client.example.test", "/"];
	}

	#[DataProvider("storedApplications")]
	public function testGetById_preservesStoredValues(?string $sender, ?string $production):void {
		$queries = $this->createMock(QueryCollection::class);
		$queries->expects(self::once())->method("fetch")->with("getById", "app")->willReturn(new Row([
			"applicationId" => "app", "name" => "Website", "emailSendFrom" => $sender,
			"emailSettings" => null, "productionApplicationDeploymentId" => $production,
			"lightThemeColours" => '{"primary":"#123456"}',
		]));
		$sut = new ApplicationRepository($this->database($queries), $this->createStub(Audit::class));

		$application = $sut->getById("app");

		self::assertSame($sender, $application->emailSendFrom);
		self::assertNull($application->emailSettings);
		self::assertSame($production, $application->productionApplicationDeploymentId);
		self::assertSame("app", $application->themes[0]->applicationId);
		self::assertSame(["primary" => "#123456"], $application->themes[0]->colours);
	}

	public static function storedApplications():iterable {
		yield "unconfigured" => [null, null];
		yield "empty stored value" => ["", null];
		yield "configured" => ["sender@example.test", "production"];
	}
}
