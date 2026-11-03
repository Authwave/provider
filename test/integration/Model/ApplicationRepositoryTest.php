<?php

namespace Authwave\Test\Integration\Model;

use Authwave\Model\ApplicationRepository;
use Authwave\Security\Audit;
use Gt\Database\Query\QueryCollection;
use Gt\Database\Result\Row;
use PDO;
use PHPUnit\Framework\TestCase;
use Authwave\Admin\Application\ApplicationName;
use Gt\Database\Database;
use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\Model\User;
use Gt\Database\Connection\Settings;
use Authwave\Admin\Application\DeploymentData;
use Gt\Http\ResponseStatusException\ClientError\HttpNotFound;

class ApplicationRepositoryTest extends TestCase {
	#[\PHPUnit\Framework\Attributes\DataProvider("data_colours")]
	public function testGetDeploymentById_migrationAndDeploymentQueriesLoadColour(?string $lightColours, ?string $darkColours):void {
		$root = dirname(__DIR__, 3);
		$pdo = new PDO("sqlite::memory:");
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec("create table Application (id text primary key, name text, emailSendFrom text, emailSettings text, productionApplicationDeploymentId text)");
		$pdo->exec("create table ApplicationDeployment (id text, applicationId text, title text, secret text, providerHost text, clientHost text, clientLoginPath text)");
		$pdo->exec("insert into Application values ('app', 'Application', 'test@example.test', null, 'deployment')");
		$pdo->exec(file_get_contents("$root/query/_migration/014-application-theme.sql"));
		foreach(["light" => $lightColours, "dark" => $darkColours] as $scheme => $colours) {
			if($colours !== null) {
				$pdo->prepare("insert into ApplicationTheme values ('app', ?, ?)")->execute([$scheme, $colours]);
			}
		}
		$pdo->exec("insert into Application values ('other', 'Other app', 'other@example.test', null, null)");
		$pdo->exec("insert into ApplicationTheme values ('other', 'dark', '{\"primary\":\"#ffffff\"}')");
		$pdo->exec("insert into ApplicationDeployment values ('deployment', 'app', 'Application', 'secret', 'provider.example.test', 'client.example.test', '/login')");

		$applicationQueries = $this->createStub(QueryCollection::class);
		$deploymentQueries = $this->createStub(QueryCollection::class);

		foreach(["application" => $applicationQueries, "application_deployment" => $deploymentQueries] as $folder => $queries) {
			$queries->method("fetch")->willReturnCallback(function(string $query, string $value)use($pdo, $root, $folder):?Row {
				$statement = $pdo->prepare(file_get_contents("$root/query/$folder/$query.sql"));
				$statement->execute([$value]);
				$row = $statement->fetch(PDO::FETCH_ASSOC);

				return $row === false ? null : new Row($row);
			});
		}

		$deploymentQueries->method("fetchString")->willReturnCallback(function(string $query, string $value)use($pdo, $root):?string {
			$statement = $pdo->prepare(file_get_contents("$root/query/application_deployment/$query.sql"));
			$statement->execute([$value]);
			$result = $statement->fetchColumn();

			return $result === false ? null : $result;
		});

		$database = $this->createStub(\Gt\Database\Database::class);
		$database->method("queryCollection")->willReturnMap([
			["application", $applicationQueries],
			["application_deployment", $deploymentQueries],
		]);
		$repository = new ApplicationRepository($database,
			$this->createStub(Audit::class));
		self::assertSame("provider.example.test", $repository->getDeploymentById("deployment")->providerHost);
		self::assertSame("provider.example.test", $repository->getDeploymentByProviderHost("provider.example.test")->providerHost);
		self::assertSame("provider.example.test", $repository->getDeploymentByClientHost("client.example.test")->providerHost);
		foreach([
			$repository->getById("app"),
			$repository->getDeploymentById("deployment")->application,
			$repository->getDeploymentByProviderHost("provider.example.test")->application,
			$repository->getDeploymentByClientHost("client.example.test")->application,
		] as $application) {
			$actual = [];
			foreach($application->themes as $theme) {
				$actual[$theme->colourScheme] = $theme->colours;
			}
			$expected = [];
			foreach(["light" => $lightColours, "dark" => $darkColours] as $scheme => $colours) {
				if($colours !== null) {
					$expected[$scheme] = json_decode($colours, true);
				}
			}
			self::assertSame($expected, $actual);
		}
	}

	public static function data_colours():array {
		return [
			[null, null],
			['{"primary":"#123456"}', null],
			[null, '{"primary":"#abcdef"}'],
			['{"primary":"#123456","panelBackground":"#fff"}', '{"primary":"#abcdef","panelBackground":"#222"}'],
			['{}', '{}'],
		];
	}

	private function repository(Database $database):ApplicationRepository {
		return new ApplicationRepository($database,
			new \Authwave\Security\Audit($database->queryCollection("audit")));
	}

	private function database():Database {
		$database = new Database(new Settings("query", "sqlite", ":memory:"));
		$database->executeSql("create table Application (id text primary key, name text not null, productionApplicationDeploymentId text)");
		$database->executeSql("create table ApplicationDeployment (id text primary key, applicationId text, clientHost text, providerHost text)");
		$database->executeSql("create table UserAdmin (userId text, applicationId text, primary key(userId, applicationId))");
		$database->executeSql("create table Audit (id text primary key, action text, detail text, userId text, anonUserId text, createdAt text)");
		return $database;
	}

	private function user(string $identifier = "admin"):User {
		return new User($identifier, new ApplicationDeployment("provider",
			new Application("provider", "Provider", "sender@example.test"),
			"Provider", "secret", "app.test", "/"), "admin@example.test");
	}

	public function testCreate_createsAnApplicationAndItsAdminAssignmentAndCanReadItBack():void {
		$database = $this->database();
		$store = $this->repository($database);
		$created = $store->create($this->user(), new ApplicationName("My first application"));
		self::assertNotEmpty($created->identifier);
		self::assertLessThanOrEqual(64, strlen($created->identifier));
		self::assertSame("My first application", $created->name);
		$loaded = $store->allFor($this->user());
		self::assertCount(1, $loaded);
		self::assertEquals($created, $loaded[0]);
		self::assertSame([], $store->allFor($this->user("another-admin")));
		$row = $database->executeSql("select * from Audit")->fetch();
		self::assertSame("APPLICATION_CREATED", $row->getString("action"));
		self::assertSame("admin", $row->getString("userId"));
		$detail = json_decode($row->getString("detail"), flags: JSON_THROW_ON_ERROR);
		self::assertFalse(property_exists($detail, "from"));
		self::assertSame($created->identifier, $detail->to->applicationId);
		self::assertSame("My first application", $detail->to->name);
	}

	public function testCreate_assignmentFailureRollsBackTheApplicationInsert():void {
		$database = $this->database();
		$database->executeSql("create trigger refuse_assignment before insert on UserAdmin begin select raise(abort, 'assignment failed'); end");
		$store = $this->repository($database);
		try {
			$store->create($this->user(), new ApplicationName("Must not remain orphaned"));
			self::fail("Expected assignment failure.");
		}
		catch(\Throwable $error) {
			self::assertStringContainsString("assignment failed", $error->getMessage());
		}
		self::assertSame(0, (int)$database->executeSql("select count(*) from Application")->fetch()->get("count(*)"));
	}

	public function testCreate_namesAreSqlParametersAndDuplicateNamesDoNotOverwriteApplications():void {
		$database = $this->database();
		$store = $this->repository($database);
		$name = new ApplicationName("'; drop table Application; --");
		$first = $store->create($this->user(), $name);
		$second = $store->create($this->user(), $name);
		self::assertNotSame($first->identifier, $second->identifier);
		self::assertCount(2, $store->allFor($this->user()));
	}

	public function testCreate_missingAdministratorIdentityCannotInsertAnApplication():void {
		$database = $this->database();
		try {
			($this->repository($database))->create($this->user(" "), new ApplicationName("My app"));
			self::fail("An administrator identity is required.");
		}
		catch(\InvalidArgumentException $error) {
			self::assertSame("An administrator identity is required.", $error->getMessage());
		}
		self::assertSame(0, $database->executeSql("select count(*) as total from Application")->fetch()->getInt("total"));
	}

	public function testCreate_auditFailureRollsBackApplicationAndAssignment():void {
		$database = $this->database();
		$database->executeSql("create trigger refuse_audit before insert on Audit begin select raise(abort, 'audit failed'); end");
		try {
			($this->repository($database))->create($this->user(), new ApplicationName("Must roll back"));
			self::fail("An unaudited application must never be committed.");
		}
		catch(\Throwable $error) {
			self::assertStringContainsString("audit failed", $error->getMessage());
		}
		foreach(["Application", "UserAdmin", "Audit"] as $table) {
			self::assertSame(0, $database->executeSql("select count(*) as total from $table")->fetch()->getInt("total"));
		}
	}

	public function testAllFor_listOnlyUsesTheSelectedProductionDeployment():void {
		$database = $this->database();
		$store = $this->repository($database);
		$application = $store->create($this->user(), new ApplicationName("My website"));
		foreach(["development", "production"] as $deployment) {
			$database->executeSql("insert into ApplicationDeployment values (?, ?, ?, ?)",
				[$deployment, $application->identifier, "https://$deployment.example.test", "login-$deployment.example.test"]);
		}
		$row = $store->allFor($this->user())[0];
		self::assertSame("-", $row->client);
		self::assertSame("-", $row->loginHost);
		$database->executeSql("update Application set productionApplicationDeploymentId = ? where id = ?",
			["production", $application->identifier]);
		$rows = $store->allFor($this->user());
		self::assertCount(1, $rows);
		self::assertSame("https://production.example.test", $rows[0]->client);
		self::assertSame("login-production.example.test", $rows[0]->loginHost);
		$database->executeSql("update Application set productionApplicationDeploymentId = 'development'");
		self::assertSame("https://development.example.test", $store->allFor($this->user())[0]->client);
		$database->executeSql("update Application set productionApplicationDeploymentId = null");
		self::assertSame("-", $store->allFor($this->user())[0]->client);
	}

	public function testAllFor_danglingAndForeignProductionReferencesDoNotExposeDeploymentHosts():void {
		$database = $this->database();
		$store = $this->repository($database);
		$application = $store->create($this->user(), new ApplicationName("My website"));
		$database->executeSql("insert into ApplicationDeployment values ('foreign', 'other-app', 'https://private.test', 'private-login.test')");
		foreach(["foreign", "missing"] as $identifier) {
			$database->executeSql("update Application set productionApplicationDeploymentId = ? where id = ?", [$identifier, $application->identifier]);
			$row = $store->allFor($this->user())[0];
			self::assertSame("-", $row->client);
			self::assertSame("-", $row->loginHost);
		}
	}

	public function testAllFor_missingHostValuesInOlderDeploymentsUseHyphens():void {
		$database = $this->database();
		$store = $this->repository($database);
		$application = $store->create($this->user(), new ApplicationName("My website"));
		$database->executeSql("insert into ApplicationDeployment values ('old', ?, '', null)", [$application->identifier]);
		$database->executeSql("update Application set productionApplicationDeploymentId = 'old'");
		$row = $store->allFor($this->user())[0];
		self::assertSame("-", $row->client);
		self::assertSame("-", $row->loginHost);
	}

	private Database $database;

	private ApplicationRepository $store;

	private User $actor;

	protected function setUp():void {
		$this->database = new Database(new Settings("query", "sqlite", ":memory:"));
		$this->database->executeSql("pragma foreign_keys = on");
		foreach(explode(";", file_get_contents("test/behat/database/schema/001-schema.sql")) as $sql) {
			if(trim($sql) !== "") $this->database->executeSql($sql);
		}
		$this->database->executeSql("insert into Application (id,name) values ('mine','My app'), ('foreign','Other app')");
		$this->database->executeSql("insert into UserAdmin values ('owner','mine'), ('other','foreign')");
		$this->store = new ApplicationRepository($this->database,
			new Audit($this->database->queryCollection("audit")));
		$this->actor = new User("owner", new ApplicationDeployment("provider",
			new Application("provider", "Provider", "sender@example.test"), "Provider", "secret", "app.test", "/"), "owner@example.test");
	}

	private function data(string $name = "Development"):DeploymentData {
		return new DeploymentData($name, "login.example.test", "https://www.example.test", "/login/");
	}

	public function testDeleteDeployment_deletionIsScopedAndAuditedWithoutExposingKeys():void {
		$deleted = $this->store->saveDeployment($this->actor, "mine", null, $this->data());
		$remaining = $this->store->saveDeployment($this->actor, "mine", null, $this->data("Staging"));
		$this->store->deleteDeployment($this->actor, "mine", $deleted->identifier);
		self::assertSame([$remaining->identifier], array_map(fn($item) => $item->identifier,
			$this->store->deployments($this->actor, "mine")));
		$row = $this->database->executeSql("select * from Audit where action = 'DEPLOYMENT_DELETED'")->fetch();
		self::assertSame("owner", $row->getString("userId"));
		$detail = json_decode($row->getString("detail"));
		self::assertSame($deleted->identifier, $detail->from->identifier);
		self::assertFalse(property_exists($detail, "to"));
		self::assertSame("mine", $detail->applicationId);
		self::assertStringNotContainsString($deleted->secret, $row->getString("detail"));
		self::assertStringNotContainsString($deleted->apiKey, $row->getString("detail"));
	}

	public function testDeleteDeployment_deletingProductionClearsAndAuditsTheSelection():void {
		$deployment = $this->store->saveDeployment($this->actor, "mine", null, $this->data());
		$this->store->save($this->actor, "mine", new ApplicationName("My app"), $deployment->identifier);
		$this->store->deleteDeployment($this->actor, "mine", $deployment->identifier);
		self::assertSame([], $this->store->deployments($this->actor, "mine"));
		self::assertNull($this->store->get($this->actor, "mine")->productionDeploymentId);
		$rows = $this->database->executeSql("select detail from Audit where action = 'APPLICATION_UPDATED'");
		$cleared = false;
		foreach($rows as $row) {
			$detail = json_decode($row->getString("detail"));
			if($detail->from->productionDeploymentId === $deployment->identifier
			&& $detail->to->productionDeploymentId === null) $cleared = true;
		}
		self::assertTrue($cleared, "Production selection removal must have its own audit record.");
	}

	public function testDeleteDeployment_missingForeignAndBlankDeletionIdentifiersCannotDeleteAnything():void {
		$deployment = $this->store->saveDeployment($this->actor, "mine", null, $this->data());
		$this->database->executeSql("insert into ApplicationDeployment values ('foreign-deployment','foreign','Foreign','secret','host.test','https://app.test','/')");
		foreach(["", " ", "0", "missing", "foreign-deployment"] as $identifier) {
			try {
				$this->store->deleteDeployment($this->actor, "mine", $identifier);
				self::fail("Invalid deployment must not be deleted");
			}
			catch(\InvalidArgumentException) {}
		}
		try {
			$this->store->deleteDeployment($this->actor, "foreign", "foreign-deployment");
			self::fail("Unowned application must not be changed");
		}
		catch(HttpNotFound) {}
		self::assertSame($deployment->identifier, $this->store->deployments($this->actor, "mine")[0]->identifier);
		self::assertSame(0, $this->database->executeSql("select count(*) as total from Audit where action = 'DEPLOYMENT_DELETED'")->fetch()->getInt("total"));
	}

	public function testDeleteDeployment_usersAndEmailHistoryPreventDeletionIncludingAtDatabaseLevel():void {
		foreach(["User", "Email"] as $dependent) {
			$deployment = $this->store->saveDeployment($this->actor, "mine", null, $this->data());
			if($dependent === "User") {
				$this->database->executeSql("insert into User values ('dependent', ?, 'user@example.test', null)", [$deployment->identifier]);
			}
			else {
				$this->database->executeSql("insert into Email values ('dependent', ?)", [$deployment->identifier]);
			}
			try {
				$this->store->deleteDeployment($this->actor, "mine", $deployment->identifier);
				self::fail("Dependent records must prevent deletion");
			}
			catch(\InvalidArgumentException $error) {
				self::assertStringContainsString("users or emails", $error->getMessage());
			}
			try {
				$this->database->executeSql("delete from ApplicationDeployment where id = ?", [$deployment->identifier]);
				self::fail("Foreign key must prevent cascading deletion");
			}
			catch(\Gt\Database\StatementExecutionException) {}
			self::assertSame(1, $this->database->executeSql("select count(*) as total from $dependent")->fetch()->getInt("total"));
		}
	}

	public function testDeleteDeployment_deletionAndProductionSelectionRollBackIfEitherAuditWriteFails():void {
		foreach(["APPLICATION_UPDATED", "DEPLOYMENT_DELETED"] as $action) {
			$deployment = $this->store->saveDeployment($this->actor, "mine", null, $this->data());
			$this->store->save($this->actor, "mine", new ApplicationName("My app"), $deployment->identifier);
			$this->database->executeSql("create trigger reject_delete_audit before insert on Audit when new.action = '$action' begin select raise(abort, 'audit failed'); end");
			try {
				$this->store->deleteDeployment($this->actor, "mine", $deployment->identifier);
				self::fail("Deletion must fail when auditing fails");
			}
			catch(\Throwable $error) { self::assertStringContainsString("audit failed", $error->getMessage()); }
			self::assertSame($deployment->identifier, $this->store->get($this->actor, "mine")->productionDeploymentId);
			self::assertSame(0, $this->database->executeSql("select count(*) as total from Audit where action = 'DEPLOYMENT_DELETED'")->fetch()->getInt("total"));
			self::assertNotEmpty($this->store->deployments($this->actor, "mine"));
			$this->database->executeSql("drop trigger reject_delete_audit");
		}
	}

	public function testSave_emptyApplicationCanBeReadAndRenamedWithAnAuditDiff():void {
		$application = $this->store->get($this->actor, "mine");
		self::assertSame("/admin/applications/mine/", $application->url);
		self::assertNull($application->productionDeploymentId);
		self::assertSame([], $this->store->deployments($this->actor, "mine"));
		$this->store->save($this->actor, "mine", new ApplicationName("Renamed"), null);
		self::assertSame("Renamed", $this->store->get($this->actor, "mine")->name);
		$row = $this->database->executeSql("select * from Audit")->fetch();
		self::assertSame("APPLICATION_UPDATED", $row->getString("action"));
		self::assertSame("owner", $row->getString("userId"));
		$detail = json_decode($row->getString("detail"));
		self::assertSame("My app", $detail->from->name);
		self::assertSame("Renamed", $detail->to->name);
	}

	public function testSave_missingAndForeignApplicationsCannotBeReadOrChanged():void {
		foreach(["missing", "foreign"] as $identifier) {
			try {
				$this->store->get($this->actor, $identifier);
				self::fail("Should not expose application");
			}
			catch(HttpNotFound) {}
			try {
				$this->store->save($this->actor, $identifier, new ApplicationName("Attack"), null);
				self::fail("Should not update Application");
			}
			catch(HttpNotFound) {}
			try {
				$this->store->saveDeployment($this->actor, $identifier, null, $this->data());
				self::fail("Should not add deployment");
			}
			catch(HttpNotFound) {}
		}
		self::assertSame(0, $this->database->executeSql("select count(*) as total from Audit")->fetch()->getInt("total"));
		self::assertSame(0, $this->database->executeSql("select count(*) as total from ApplicationDeployment")->fetch()->getInt("total"));
	}

	public function testSaveDeployment_deploymentCreationUpdateProductionAndKeyStability():void {
		$created = $this->store->saveDeployment($this->actor, "mine", null, $this->data());
		self::assertSame(32, strlen($created->secret));
		$repository = new \Authwave\Model\ApplicationRepository($this->database,
			new Audit($this->database->queryCollection("audit")));
		$usable = $repository->getDeploymentById($created->identifier);
		self::assertSame("https://www.example.test/login/", (string)$usable->getClientReturnUri());
		self::assertSame($created->secret, $usable->secret);
		self::assertSame($created->identifier . "_" . $created->secret, $created->apiKey);
		// The key suffix is directly usable by the existing PHP client/cipher contract.
		$cipher = (new \GT\Cipher\Message\PlainTextMessage("test", new \GT\Cipher\InitVector()))
			->encrypt(new \GT\Cipher\Key($created->secret));
		self::assertNotEmpty((string)$cipher);
		$updated = $this->store->saveDeployment($this->actor, "mine", $created->identifier,
			new DeploymentData("Production", "account.example.test", "http://localhost:8080/", "/authenticate/"));
		self::assertSame($created->apiKey, $updated->apiKey);
		$createdAudit = json_decode($this->database->executeSql("select detail from Audit where action = 'DEPLOYMENT_CREATED'")->fetch()->getString("detail"));
		self::assertFalse(property_exists($createdAudit, "from"));
		self::assertSame($created->identifier, $createdAudit->to->identifier);
		self::assertSame("mine", $createdAudit->applicationId);
		$updatedAudit = json_decode($this->database->executeSql("select detail from Audit where action = 'DEPLOYMENT_UPDATED'")->fetch()->getString("detail"));
		self::assertSame("Development", $updatedAudit->from->name);
		self::assertSame("Production", $updatedAudit->to->name);
		self::assertSame("login.example.test", $updatedAudit->from->host);
		self::assertSame("account.example.test", $updatedAudit->to->host);
		self::assertFalse(property_exists($updatedAudit->to, "apiKey"));
		self::assertSame("Production", $this->store->deployments($this->actor, "mine")[0]->name);
		$this->store->save($this->actor, "mine", new ApplicationName("My app"), $created->identifier);
		self::assertSame($created->identifier, $this->store->get($this->actor, "mine")->productionDeploymentId);
		foreach($this->database->executeSql("select detail from Audit") as $row) {
			self::assertStringNotContainsString($created->secret, $row->getString("detail"));
			self::assertStringNotContainsString($created->apiKey, $row->getString("detail"));
		}
		$this->store->save($this->actor, "mine", new ApplicationName("My app"), "");
		self::assertNull($this->store->get($this->actor, "mine")->productionDeploymentId);
	}

	public function testSaveDeployment_foreignDeploymentCannotBeEditedOrUsedAsProduction():void {
		$this->database->executeSql("insert into ApplicationDeployment values ('foreign-deployment','foreign','Foreign','secret','host.test','https://app.test','/')");
		foreach(["foreign-deployment", "missing", "0"] as $identifier) {
			try {
				$this->store->save($this->actor, "mine", new ApplicationName("Attack"), $identifier);
				self::fail("Should reject production selection");
			}
			catch(\InvalidArgumentException) {}
			try {
				$this->store->saveDeployment($this->actor, "mine", $identifier, $this->data());
				self::fail("Should reject deployment update");
			}
			catch(\InvalidArgumentException) {}
		}
		self::assertSame("My app", $this->store->get($this->actor, "mine")->name);
		self::assertSame([], $this->store->deployments($this->actor, "mine"));
	}

	public function testSave_auditFailuresRollBackAllKindsOfChanges():void {
		$deployment = $this->store->saveDeployment($this->actor, "mine", null, $this->data());
		$this->database->executeSql("create trigger reject_audit before insert on Audit begin select raise(abort, 'audit failed'); end");
		foreach([
			fn() => $this->store->save($this->actor, "mine", new ApplicationName("Wrong"), $deployment->identifier),
			fn() => $this->store->saveDeployment($this->actor, "mine", null, $this->data("Wrong")),
			fn() => $this->store->saveDeployment($this->actor, "mine", $deployment->identifier, $this->data("Wrong")),
		] as $operation) {
			try { $operation(); self::fail("Expected audit failure"); }
			catch(\Throwable $error) { self::assertStringContainsString("audit failed", $error->getMessage()); }
		}
		self::assertSame("My app", $this->store->get($this->actor, "mine")->name);
		self::assertNull($this->store->get($this->actor, "mine")->productionDeploymentId);
		self::assertCount(1, $this->store->deployments($this->actor, "mine"));
		self::assertSame("Development", $this->store->deployments($this->actor, "mine")[0]->name);
	}
}
