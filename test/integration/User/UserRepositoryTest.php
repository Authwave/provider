<?php
namespace Authwave\Test\Integration\User;

use GT\Database\Database;
use Authwave\Model\{Application, ApplicationDeployment, ApplicationRepository, User, EmailSettings};
use Authwave\Security\DomainVerification;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use PHPUnit\Framework\Attributes\DataProvider;
use Authwave\User\UserRepository;
use Authwave\Email\EmailRepository;
use Authwave\Security\Audit;
use GT\Database\Connection\Settings;
use PHPUnit\Framework\TestCase;

class UserRepositoryTest extends TestCase {
	public function testGet_emailLookupIsScopedToDeploymentAndMissingUsersReturnNull():void {
		$database = new Database(new Settings("query", "sqlite", ":memory:"));
		$database->executeSql("create table User (id text, applicationDeploymentId text, email text, hash text, createdAt text, clientNotificationStatus integer, clientNotificationAcknowledged integer, clientNotificationAction text)");
		$database->executeSql("insert into User (id, applicationDeploymentId, email, hash) values ('first', 'one', 'user@example.test', null), ('second', 'two', 'user@example.test', null), ('orphan', 'missing', 'orphan@example.test', null)");

		$application = new Application("app", "App", "sender@example.test");
		$first = new ApplicationDeployment("one", $application, "One", "secret", "client.test", "/");
		$second = new ApplicationDeployment("two", $application, "Two", "secret", "client.test", "/");
		$applications = $this->createStub(ApplicationRepository::class);
		$applications->method("getDeploymentById")->willReturnMap([
			["one", $first], ["two", $second], ["missing", null],
		]);
		$sut = new UserRepository($database, $applications,
			$this->createStub(EmailRepository::class), $this->createStub(Audit::class));

		self::assertSame("first", $sut->get($first, "user@example.test")->id);
		self::assertSame("second", $sut->get($second, "user@example.test")->id);
		self::assertSame("first", $sut->getById("first")->id);
		self::assertNull($sut->get($first, "absent@example.test"));
		self::assertNull($sut->getById(null));
		self::assertNull($sut->getById("absent"));
		self::assertNull($sut->getById("orphan"));
	}

	private function adminDatabase():Database {
		$database = new Database(new Settings("query", "sqlite", ":memory:"));

		foreach(explode(";", file_get_contents("test/behat/database/schema/001-schema.sql")) as $sql) {
			if(trim($sql) !== "") {
				$database->executeSql($sql);
			}
		}

		$database->executeSql("insert into Application (id,name,emailSendFrom) values ('app','Example','sender@example.test'), ('foreign','Other',null)");
		$database->executeSql("insert into ApplicationDeployment (id,applicationId,title,secret,providerHost,clientHost,clientLoginPath) values ('deployment','app','Production','secret','login.test','https://client.test','/login/'), ('foreign','foreign','Private','secret','private.test','https://private.test','/')");
		$database->executeSql("insert into User (id,applicationDeploymentId,email) values ('admin','deployment','admin@example.test')");
		$database->executeSql("insert into UserAdmin values ('admin','app')");
		return $database;
	}

	private function administrator(Database $database):User {
		$apps = new ApplicationRepository($database, new Audit($database->queryCollection("audit")),
			new DomainVerification());
		return new User("admin", $apps->getDeploymentById("deployment"), "admin@example.test");
	}

	private function adminRepository(Database $database, ?Audit $audit = null):UserRepository {
		$audit ??= new Audit($database->queryCollection("audit"));
		return new UserRepository($database,
			new ApplicationRepository($database, $audit, new DomainVerification()),
			new EmailRepository($database->queryCollection("email"),
				new EmailSettings("localhost", 1025, "", ""), $audit), $audit);
	}

	#[DataProvider("manualAccounts")]
	public function testCreateForAdministrator_createsChosenAuthenticationAndOptionalInstructions(?string $password,
		bool $instructions):void {
		$database = $this->adminDatabase();
		$actor = $this->administrator($database);
		$sut = $this->adminRepository($database);

		$user = $sut->createForAdministrator($actor, "app", "deployment", " NEW@example.test ", $password, $instructions);

		self::assertSame("new@example.test", $user->email);
		self::assertNotNull($user->createdAt);
		self::assertSame($password !== null, $user->hash !== null && password_verify($password, $user->hash));
		self::assertSame(0, (int)$database->executeSql("select count(*) as n from UserAuthCode")->fetch()->getInt("n"));
		$row = $database->executeSql("select userId,detail from Audit where action='USER_CREATED'")->fetch();
		self::assertSame("admin", $row->getString("userId"));
		self::assertStringNotContainsString("password", $row->getString("detail"));
		$email = $database->executeSql("select * from Email")->fetch();
		self::assertSame($instructions, $email !== null);

		if($instructions) {
			self::assertSame("new@example.test", $email->getString("toEmail"));
			self::assertStringContainsString('href="https://client.test/login/"', $email->getString("htmlContent"));
			self::assertStringContainsString("Example", $email->getString("subject"));

			if($password) {
				self::assertStringNotContainsString($password, $email->getString("textContent"));
			}
		}
	}

	public function testRecentLogins_returnsOnlySuccessfulLoginsNewestFirstWithALimit():void {
		$database = $this->adminDatabase();
		$sut = $this->adminRepository($database);
		$actor = $this->administrator($database);

		for($day = 1; $day <= 12; $day++) {
			$database->executeSql("insert into Audit(id,userId,action,createdAt) values (?, 'admin', 'LOGIN_COMPLETED', ?)",
				["login-$day", sprintf("2026-01-%02d 12:00:00", $day)]);
		}

		$database->executeSql("insert into Audit(id,userId,action,createdAt) values ('failure','admin','PASSWORD_LOGIN_FAILED','2026-02-01 12:00:00')");
		$logins = $sut->recentLogins($actor, $actor);
		self::assertCount(10, $logins);
		self::assertSame("2026-01-12 12:00:00", $logins[0]->format("Y-m-d H:i:s"));
		self::assertSame("2026-01-03 12:00:00", $logins[9]->format("Y-m-d H:i:s"));
	}

	public function testDeploymentsForEmail_isLimitedToTheOwnedApplication():void {
		$database = $this->adminDatabase();
		$database->executeSql("insert into ApplicationDeployment(id,applicationId,title,secret,providerHost,clientHost,clientLoginPath) values ('staging','app','Staging','secret','stage.test','https://client.test','/')");
		$database->executeSql("insert into User(id,applicationDeploymentId,email) values ('staging-user','staging','admin@example.test'),('private-user','foreign','admin@example.test')");
		$sut = $this->adminRepository($database);
		$actor = $this->administrator($database);

		$deployments = $sut->deploymentsForEmail($actor, $actor);

		self::assertSame(["deployment", "staging"], array_map(fn($deployment) => $deployment->id, $deployments));
	}

	public function testDelete_removesTheUserAndSessionsWithAnAuditSnapshot():void {
		$database = $this->adminDatabase();
		$database->executeSql("pragma foreign_keys = on");
		$sut = $this->adminRepository($database);
		$actor = $this->administrator($database);
		$user = $sut->createForAdministrator($actor, "app", "deployment", "delete@example.test", null, false);
		$database->executeSql("insert into UserSession(id,userId,createdAt,expiresAt) values ('session',?, '2026-01-01','2030-01-01')", [$user->id]);

		$sut->delete($actor, $user);

		self::assertNull($sut->getById($user->id));
		self::assertNull($database->executeSql("select * from UserSession where id='session'")->fetch());
		$row = $database->executeSql("select * from Audit where action='USER_DELETED'")->fetch();
		self::assertSame("admin", $row->getString("userId"));
		self::assertSame($user->id, json_decode($row->getString("detail"))->from->id);
	}

	public function testDelete_allowsDeletingTheActorAndPreservesTheAudit():void {
		$database = $this->adminDatabase();
		$database->executeSql("pragma foreign_keys = on");
		$database->executeSql("drop table Audit");
		$database->executeSql("create table Audit(id text primary key, action text, detail text,
			userId text references User(id) on delete set null, anonUserId text,
			createdAt text default CURRENT_TIMESTAMP)");
		$sut = $this->adminRepository($database);
		$actor = $this->administrator($database);

		$sut->delete($actor, $actor);

		self::assertNull($sut->getById($actor->id));
		$row = $database->executeSql("select * from Audit where action='USER_DELETED'")->fetch();
		self::assertNull($row->getString("userId"));
		self::assertSame($actor->id, json_decode($row->getString("detail"))->from->id);
	}

	public function testDelete_auditFailureRollsBackTheUserAndSessionDeletion():void {
		$database = $this->adminDatabase();
		$database->executeSql("pragma foreign_keys = on");
		$sut = $this->adminRepository($database);
		$actor = $this->administrator($database);
		$user = $sut->createForAdministrator($actor, "app", "deployment", "delete@example.test", null, false);
		$database->executeSql("insert into UserSession(id,userId,createdAt,expiresAt) values ('session',?, '2026-01-01','2030-01-01')", [$user->id]);
		$database->executeSql("create trigger refuse_deletion before insert on Audit when new.action = 'USER_DELETED' begin select raise(abort, 'audit failed'); end");

		try {
			$sut->delete($actor, $user);
			self::fail("Expected audit failure");
		}
		catch(Throwable $error) {
			self::assertStringContainsString("audit failed", $error->getMessage());
			self::assertNotNull($sut->getById($user->id));
			self::assertNotNull($database->executeSql("select * from UserSession where id='session'")->fetch());
		}
	}

	public static function manualAccounts():iterable {
		yield [null, false];
		yield [null, true];
		yield ["chosen-password-2026", false];
		yield ["chosen-password-2026", true];
	}

	public function testCreateForAdministrator_duplicateDoesNotChangeExistingCredentials():void {
		$database = $this->adminDatabase();
		$actor = $this->administrator($database);
		$sut = $this->adminRepository($database);
		$user = $sut->createForAdministrator($actor, "app", "deployment", "member@example.test", "original-password", false);

		try {
			$sut->createForAdministrator($actor, "app", "deployment", "MEMBER@example.test", "replacement-password", true);
			self::fail("Duplicate account must be rejected");
		}
		catch(InvalidArgumentException) {
			self::assertTrue(password_verify("original-password", $sut->getById($user->id)->hash));
			self::assertSame(0, (int)$database->executeSql("select count(*) as n from Email")->fetch()->getInt("n"));
		}
	}

	#[DataProvider("foreignDeployments")]
	public function testCreateForAdministrator_rejectsForeignAndMissingDeployments(string $deployment):void {
		$database = $this->adminDatabase();
		$sut = $this->adminRepository($database);
		$this->expectException(InvalidArgumentException::class);

		$sut->createForAdministrator($this->administrator($database), "app", $deployment, "new@example.test", null, false);
	}

	public static function foreignDeployments():iterable {
		yield ["foreign"];
		yield ["missing"];
		yield [""];
	}

	public function testCreateForAdministrator_auditFailureRollsBackUser():void {
		$database = $this->adminDatabase();
		$actor = $this->administrator($database);
		$audit = $this->createStub(Audit::class);
		$audit->method("recordChange")->willThrowException(new RuntimeException("Audit failed"));
		$sut = $this->adminRepository($database, $audit);

		try {
			$sut->createForAdministrator($actor, "app", "deployment", "new@example.test", null, false);
			self::fail("Expected audit failure");
		}
		catch(RuntimeException) {
			self::assertNull($sut->get($actor->deployment, "new@example.test"));
		}
	}

	public function testCreateForAdministrator_emailQueueFailureRollsBackUserAndAudit():void {
		$database = $this->adminDatabase();
		$actor = $this->administrator($database);
		$database->executeSql("create trigger refuse_email before insert on Email begin select raise(abort, 'queue failed'); end");
		$sut = $this->adminRepository($database);

		try {
			$sut->createForAdministrator($actor, "app", "deployment", "new@example.test", null, true);
			self::fail("Expected email queue failure");
		}
		catch(Throwable $error) {
			self::assertStringContainsString("queue failed", $error->getMessage());
			self::assertNull($sut->get($actor->deployment, "new@example.test"));
			self::assertSame(0, (int)$database->executeSql("select count(*) as n from Audit")->fetch()->getInt("n"));
		}
	}

	public function testSearch_selectsABookmarkedUserWithoutExposingOtherApplications():void {
		$database = $this->adminDatabase();
		$database->executeSql("insert into User(id,applicationDeploymentId,email) values ('private','foreign','private@example.test')");
		$actor = $this->administrator($database);
		$sut = $this->adminRepository($database);

		$rows = $sut->search($actor, "app", userId: "admin");

		self::assertCount(1, $rows);
		self::assertSame("admin", $rows[0]->user->id);
		self::assertSame([], $sut->search($actor, "app", userId: "private"));
		self::assertSame([], $sut->search($actor, "app", userId: "missing"));
	}

	public function testSearch_scopesOwnershipAndEscapesWildcards():void {
		$database = $this->adminDatabase();
		$actor = $this->administrator($database);
		$sut = $this->adminRepository($database);
		$sut->createForAdministrator($actor, "app", "deployment", "percent%match@example.test", null, false);
		$sut->createForAdministrator($actor, "app", "deployment", "other@example.test", null, false);
		$database->executeSql("insert into User (id,applicationDeploymentId,email) values ('private','foreign','private@example.test')");

		$rows = $sut->search($actor, "app", "%");

		self::assertCount(1, $rows);
		self::assertSame("percent%match@example.test", $rows[0]->user->email);
		self::assertSame(3, $sut->countSearch($actor, "app"));
		self::assertSame([], $sut->search($actor, "app", "private"));
	}

	public function testSearch_acceptsAsteriskWildcardsAndKeepsSqlWildcardsLiteral():void {
		$database = $this->adminDatabase();
		$actor = $this->administrator($database);
		$database->executeSql("insert into User (id,applicationDeploymentId,email) values ('match','deployment','alice+team@example.test'), ('literal','deployment','alice_team@example.test'), ('other','deployment','alice@example.org'), ('prefix','deployment','notalice@example.test'), ('suffix','deployment','alice@example.test.invalid')");
		$sut = $this->adminRepository($database);

		$matches = $sut->search($actor, "app", "alice*@*.test");

		self::assertCount(2, $matches);
		self::assertSame(2, $sut->countSearch($actor, "app", "alice*@*.test"));
		self::assertSame("alice_team@example.test", $sut->search($actor, "app", "alice_*")[0]->user->email);
		self::assertCount(1, $sut->search($actor, "app", "alice_*"));
		self::assertCount(6, $sut->search($actor, "app", "*"));
	}

	public function testSearch_paginatesWithoutRepeatingUsers():void {
		$database = $this->adminDatabase();
		$actor = $this->administrator($database);

		for($index = 0; $index < UserRepository::PAGE_SIZE + 1; $index++) {
			$email = sprintf("member%02d@example.test", $index);
			$database->executeSql("insert into User (id,applicationDeploymentId,email) values (?,'deployment',?)",
				["member-$index", $email]);
		}

		$sut = $this->adminRepository($database);

		$first = $sut->search($actor, "app", "member", page: 1);
		$second = $sut->search($actor, "app", "member", page: 2);

		self::assertCount(UserRepository::PAGE_SIZE, $first);
		self::assertCount(1, $second);
		self::assertSame("member00@example.test", $first[0]->user->email);
		self::assertSame("member24@example.test", $first[24]->user->email);
		self::assertSame("member25@example.test", $second[0]->user->email);
		self::assertSame([], $sut->search($actor, "app", "member", page: 3));
	}

	public function testSearch_filtersDeploymentWithinTheOwnedApplication():void {
		$database = $this->adminDatabase();
		$actor = $this->administrator($database);
		$database->executeSql("insert into ApplicationDeployment (id,applicationId,title,secret,providerHost,clientHost,clientLoginPath) values ('staging','app','Staging','secret','staging.test','https://client.test','/')");
		$database->executeSql("insert into User (id,applicationDeploymentId,email) values ('staging-user','staging','member@example.test'), ('private','foreign','private@example.test')");
		$sut = $this->adminRepository($database);

		$rows = $sut->search($actor, "app", deploymentId: "staging");

		self::assertCount(1, $rows);
		self::assertSame("staging-user", $rows[0]->user->id);
		self::assertSame(1, $sut->countSearch($actor, "app", deploymentId: "staging"));
		self::assertSame(2, $sut->countSearch($actor, "app"));
		self::assertSame([], $sut->search($actor, "app", deploymentId: "foreign"));
		self::assertSame(0, $sut->countSearch($actor, "app", deploymentId: "foreign"));
		self::assertSame([], $sut->search($actor, "app", deploymentId: "missing"));
		self::assertSame(0, $sut->countSearch($actor, "app", deploymentId: "missing"));
	}

	public function testStartSession_countsSeparateBrowsersButReusesTheSameBrowserSession():void {
		$database = $this->adminDatabase();
		$user = $this->administrator($database);
		$sut = $this->adminRepository($database);

		$first = $sut->startSession($user);
		$repeated = $sut->startSession($user, $first->id);
		$second = $sut->startSession($user);

		self::assertSame($first->id, $repeated->id);
		self::assertNotSame($first->id, $second->id);
		self::assertSame(1800, $first->expiresAt->getTimestamp() - $first->createdAt->getTimestamp());
		self::assertCount(2, $sut->currentlyLoggedIn($user, "app"));
		self::assertSame(2, $database->executeSql("select count(*) as n from Audit where action='LOGIN_COMPLETED'")->fetch()->getInt("n"));
	}

	public function testStartSession_replacesThePreviousAccountInTheSameBrowser():void {
		$database = $this->adminDatabase();
		$user = $this->administrator($database);
		$sut = $this->adminRepository($database);
		$previous = $sut->startSession($user);
		$member = $sut->createForAdministrator($user, "app", "deployment", "member@example.test", null, false);

		$current = $sut->startSession($member, $previous->id);

		$active = $sut->currentlyLoggedIn($user, "app");
		self::assertCount(1, $active);
		self::assertSame($current->id, $active[0]->id);
		self::assertSame($member->id, $active[0]->userId);
	}

	public function testEndSession_closesOnlyTheMatchingDeploymentAndBrowserAndAuditsItsUser():void {
		$database = $this->adminDatabase();
		$user = $this->administrator($database);
		$sut = $this->adminRepository($database);
		$first = $sut->startSession($user);
		$second = $sut->startSession($user);
		$foreign = new ApplicationDeployment("foreign", new Application("foreign", "Other", null), "Other", "secret", "other.test", "/");

		self::assertFalse($sut->endSession($foreign, $first->id));
		self::assertFalse($sut->endSession($user->deployment, "missing"));
		self::assertFalse($sut->endSession($user->deployment, null));
		self::assertTrue($sut->endSession($user->deployment, $first->id));
		self::assertFalse($sut->endSession($user->deployment, $first->id));
		$active = $sut->currentlyLoggedIn($user, "app");
		self::assertCount(1, $active);
		self::assertSame($second->id, $active[0]->id);
		$audit = $database->executeSql("select userId,detail from Audit where action='LOGOUT_REQUESTED'")->fetch();
		self::assertSame($user->id, $audit->getString("userId"));
		self::assertSame($first->id, json_decode($audit->getString("detail"))->sessionId);
	}

	public function testCurrentlyLoggedIn_excludesExpiredEndedAndForeignSessions():void {
		$database = $this->adminDatabase();
		$user = $this->administrator($database);
		$database->executeSql("insert into User (id,applicationDeploymentId,email) values ('private','foreign','private@example.test')");
		$past = (new \DateTimeImmutable("-1 minute"))->format("Y-m-d H:i:s");
		$future = (new \DateTimeImmutable("+1 minute"))->format("Y-m-d H:i:s");
		$database->executeSql("insert into UserSession values ('expired','admin',?,?,null), ('ended','admin',?,?,?), ('private-session','private',?,?,null)",
			[$past, $past, $past, $future, $past, $past, $future]);
		$sut = $this->adminRepository($database);

		self::assertSame([], $sut->currentlyLoggedIn($user, "app"));
	}

	public function testStartSession_usesConfiguredDuration():void {
		$database = $this->adminDatabase();
		$user = $this->administrator($database);
		$audit = new Audit($database->queryCollection("audit"));
		$sut = new UserRepository($database,
			new ApplicationRepository($database, $audit, new DomainVerification()),
			$this->createStub(EmailRepository::class), $audit, sessionDuration: 90);

		$session = $sut->startSession($user);

		self::assertSame(90, $session->expiresAt->getTimestamp() - $session->createdAt->getTimestamp());
	}

	public function testStartSession_auditFailureRollsBackSessionCreation():void {
		$database = $this->adminDatabase();
		$user = $this->administrator($database);
		$audit = $this->createStub(Audit::class);
		$audit->method("create")->willThrowException(new RuntimeException("audit failed"));
		$sut = $this->adminRepository($database, $audit);

		try {
			$sut->startSession($user);
			self::fail("Expected audit failure");
		}
		catch(RuntimeException) {
			self::assertSame([], $sut->currentlyLoggedIn($user, "app"));
		}
	}

	public function testEndSession_auditFailureLeavesTheSessionActive():void {
		$database = $this->adminDatabase();
		$user = $this->administrator($database);
		$future = (new \DateTimeImmutable("+1 minute"))->format("Y-m-d H:i:s");
		$database->executeSql("insert into UserSession values ('active','admin',?,?,null)", [$future, $future]);
		$audit = $this->createStub(Audit::class);
		$audit->method("create")->willThrowException(new RuntimeException("audit failed"));
		$sut = $this->adminRepository($database, $audit);

		try {
			$sut->endSession($user->deployment, "active");
			self::fail("Expected audit failure");
		}
		catch(RuntimeException) {
			self::assertCount(1, $sut->currentlyLoggedIn($user, "app"));
		}
	}

	public function testLatestFailures_readsScopedAuditDataAndIgnoresOldFailures():void {
		$database = $this->adminDatabase();
		$actor = $this->administrator($database);
		$sut = $this->adminRepository($database);
		$user = $sut->createForAdministrator($actor, "app", "deployment", "member@example.test", null, false);
		$database->executeSql("insert into Audit (id,action,userId,createdAt) values ('first','LOGIN_COMPLETED',?,'2026-01-01 00:00:00'), ('failed','PASSWORD_LOGIN_FAILED',?,CURRENT_TIMESTAMP), ('old','SECURITY_CODE_REJECTED','admin','2000-01-01 00:00:00')", [$user->id,$user->id]);

		$rows = $sut->latestFailures($actor, "app");

		self::assertCount(1, $rows);
		self::assertSame($user->id, $rows[0]->user->id);
		self::assertSame(1, $rows[0]->loginCount);
		self::assertNotNull($rows[0]->lastFailureAt);
	}
}
