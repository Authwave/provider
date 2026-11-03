<?php

namespace Authwave\Test\User;

use Authwave\Model\ApplicationRepository;
use Authwave\Security\Action;
use Authwave\Security\Audit;
use Authwave\Model\User;
use Authwave\User\UserRepository;
use Authwave\Email\EmailRepository;
use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Gt\Database\Query\QueryCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Gt\Database\Result\Row;

class UserRepositoryTest extends TestCase {
	#[DataProvider("passwordAttempts")]
	public function testCheckLogin_passwordAttemptsAreAuditedWithoutLoggingCredentials(
		string $password, bool $success, Action $action,
	):void {
		$deployment = new ApplicationDeployment("deployment", new Application("app", "App", "sender@example.test"), "Provider", "secret", "app.test", "/");
		$user = new User("user", $deployment, "user@example.test");
		$query = $this->createStub(QueryCollection::class);
		$query->method("fetchString")->willReturn(password_hash("correct-password", PASSWORD_DEFAULT));
		$audit = $this->createMock(Audit::class);
		$audit->expects(self::once())->method("create")->with($action, ["deploymentId" => "deployment"], $user);
		$sut = new UserRepository($query, $this->createStub(ApplicationRepository::class),
			$this->createStub(EmailRepository::class), $audit);
		self::assertSame($success, $sut->checkLogin($user, $password));
	}

	public static function passwordAttempts():iterable {
		yield ["correct-password", true, Action::PASSWORD_LOGIN_SUCCEEDED];
		yield ["incorrect-password", false, Action::PASSWORD_LOGIN_FAILED];
		yield ["", false, Action::PASSWORD_LOGIN_FAILED];
	}

	#[DataProvider("securityCodeAttempts")]
	public function testCheckAuthCode_codeOutcomeAndPasswordChangesAreAudited(
		string $submitted, bool $success, bool $changed,
	):void {
		$deployment = new ApplicationDeployment("deployment", new Application("app", "App", "sender@example.test"), "Provider", "secret", "app.test", "/");
		$user = new User("user", $deployment, "user@example.test");
		$query = $this->createMock(QueryCollection::class);
		$hashReads = 0;
		$query->method("fetchString")->willReturnCallback(function(string $name)use(&$hashReads, $changed) {
			if($name === "getLatestAuthCode") {
				return "12345";
			}

			return ++$hashReads === 1 || !$changed ? "old-hash" : "new-hash";
		});
		$query->expects($success ? self::once() : self::never())->method("update");
		$query->expects($success ? self::once() : self::never())->method("delete");
		$row = $this->createStub(Row::class);
		$row->method("getString")->willReturnCallback(fn($key) => ["userId" => "user", "applicationDeploymentId" => "deployment", "email" => "user@example.test", "hash" => null][$key]);
		$query->method("fetch")->willReturn($row);
		$applications = $this->createStub(ApplicationRepository::class);
		$applications->method("getDeploymentById")->willReturn($deployment);
		$actions = [];
		$audit = $this->createMock(Audit::class);
		$audit->expects(self::exactly($changed ? 2 : 1))->method("create")
			->willReturnCallback(function(Action $action, array|null $detail)use(&$actions):void {
				$actions[] = $action;
				$json = json_encode($detail, JSON_THROW_ON_ERROR);
				self::assertStringNotContainsString("12345", $json);
				self::assertStringNotContainsString("old-hash", $json);
				self::assertStringNotContainsString("new-hash", $json);
				if($action === Action::PASSWORD_CHANGED) {
					self::assertNull($detail);
				}
			});
		$sut = new UserRepository($query, $applications, $this->createStub(EmailRepository::class), $audit);
		self::assertSame($success, $sut->checkAuthCode($user, $submitted));
		self::assertSame($success ? Action::SECURITY_CODE_ACCEPTED : Action::SECURITY_CODE_REJECTED, $actions[0]);
		if($changed) {
			self::assertSame(Action::PASSWORD_CHANGED, $actions[1]);
		}
	}

	public static function securityCodeAttempts():iterable {
		yield ["wrong", false, false];
		yield ["12345", true, false];
		yield ["12345", true, true];
	}

	#[DataProvider("users")]
	public function testGet_looksUpIdWithinDeployment(bool $exists):void {
		$deployment = new ApplicationDeployment("deployment", new Application("app", "App", "sender@example.test"),
			"Provider", "secret", "app.test", "/");
		$query = $this->createMock(QueryCollection::class);
		$query->expects(self::once())->method("fetchString")
			->with("getIdByDeploymentAndEmail", "deployment", "user@example.test")->willReturn($exists ? "user" : null);
		$query->expects($exists ? self::once() : self::never())->method("fetch")
			->with("getById", "user")->willReturn(new Row([
				"userId" => "user", "applicationDeploymentId" => "deployment", "email" => "user@example.test",
			]));
		$applications = $this->createStub(ApplicationRepository::class);
		$applications->method("getDeploymentById")->willReturn($deployment);
		$sut = new UserRepository($query, $applications, $this->createStub(EmailRepository::class), $this->createStub(Audit::class));

		self::assertSame($exists ? "user" : null, $sut->get($deployment, "user@example.test")?->id);
	}

	public static function users():iterable {
		yield "found" => [true];
		yield "absent" => [false];
	}

	#[DataProvider("storedHashes")]
	public function testGetById_preservesStoredHashAndDeploymentId(?string $hash):void {
		$deployment = new ApplicationDeployment("deployment", new Application("app", "Website", null),
			"Provider", "secret", "app.test", "/");
		$query = $this->createMock(QueryCollection::class);
		$query->expects(self::once())->method("fetch")->with("getById", "user")->willReturn(new Row([
			"userId" => "user", "applicationDeploymentId" => "deployment",
			"email" => "user@example.test", "hash" => $hash,
		]));
		$applications = $this->createMock(ApplicationRepository::class);
		$applications->expects(self::once())->method("getDeploymentById")->with("deployment")->willReturn($deployment);
		$sut = new UserRepository($query, $applications, $this->createStub(EmailRepository::class), $this->createStub(Audit::class));

		$user = $sut->getById("user");

		self::assertSame($hash, $user->hash);
		self::assertSame("deployment", $user->applicationDeploymentId);
		self::assertSame($deployment, $user->deployment);
	}

	public static function storedHashes():iterable {
		yield "passwordless" => [null];
		yield "password hash" => ["stored-password-hash"];
	}
}
