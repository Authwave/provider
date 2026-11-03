<?php

namespace Authwave\Test\Admin\Onboarding;

use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Model\ApplicationRepository;
use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\Security\AdminAccess;
use Authwave\Security\AdminAccessDenied;
use Authwave\Session\LoginSession;
use Authwave\User\LoginState;
use Authwave\Model\User;
use Authwave\User\UserRepository;
use Gt\Http\Response;
use Gt\Http\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminAreaTest extends TestCase {
	public function testEnter_unknownProviderHostIsHandledByTheCaller():void {
		$login = $this->createMock(LoginSession::class);
		$login->method("getDeployment")->willReturn(null);
		$login->expects(self::never())->method("setDeploymentForLogin");
		$applications = $this->createMock(ApplicationRepository::class);
		$applications->expects(self::once())->method("getDeploymentByProviderHost")
			->with("absent.test")->willReturn(null);
		$response = $this->createMock(Response::class);
		$response->expects(self::never())->method("redirect");
		$sut = new AdminArea($login, $this->createStub(UserRepository::class),
			$this->createStub(AdminAccess::class), $applications);

		$this->expectException(\Gt\Http\ResponseStatusException\ClientError\HttpNotFound::class);
		$sut->enter(new Uri("https://absent.test/admin/"), $response);
	}

	#[DataProvider("logoutAudits")]
	public function testLogout_logoutClearsTheAuthenticatedLoginBeforeEndingTheSession(bool $auditFails):void {
		$deployment = $this->deployment();
		$user = new User("admin", $deployment, "admin@example.test");
		$login = $this->createMock(LoginSession::class);
		$login->method("getDeployment")->willReturn($deployment);
		$login->method("getEmail")->willReturn($user->email);
		$login->method("getState")->willReturn(LoginState::LOGGED_IN);
		$cleared = false;
		$login->expects(self::once())->method("clearDataForLogout")->with($deployment, $user)
			->willReturnCallback(function() use (&$cleared):void {
				$cleared = true;
			});
		if($auditFails) {
			$login->method("clearDataForLogout")->willThrowException(new \RuntimeException("Audit failed"));
		}

		$users = $this->createStub(UserRepository::class);
		$users->method("get")->willReturn($user);
		$access = $this->createStub(AdminAccess::class);
		$access->method("allows")->willReturn(true);
		$session = $this->createMock(\Gt\Session\Session::class);
		$session->expects($auditFails ? self::never() : self::once())->method("kill")->willReturnCallback(function() use (&$cleared):void {
			self::assertTrue($cleared);
		});

		$sut = new AdminArea($login, $users, $access, $this->createStub(ApplicationRepository::class));
		if($auditFails) {
			$this->expectException(\RuntimeException::class);
		}

		$sut->logout($session);
	}

	public static function logoutAudits():iterable {
		yield "audit succeeds" => [false];
		yield "audit fails" => [true];
	}

	#[DataProvider("unauthorised")]
	public function testLogout_unauthorisedLogoutDoesNotClearOrEndTheSession(
		bool $loggedIn, bool $hasDeployment, ?string $email, bool $hasUser, bool $allowed,
	):void {
		$deployment = $this->deployment();
		$login = $this->createMock(LoginSession::class);
		$login->method("getDeployment")->willReturn($hasDeployment ? $deployment : null);
		$login->method("getState")->willReturn($loggedIn ? LoginState::LOGGED_IN : LoginState::NOT_LOGGED_IN);
		$login->method("getEmail")->willReturn($email);
		$login->expects(self::never())->method("clearDataForLogout");
		$users = $this->createStub(UserRepository::class);
		$users->method("get")->willReturn($hasUser ? new User("admin", $deployment, "a@b.test") : null);
		$access = $this->createStub(AdminAccess::class);
		$access->method("allows")->willReturn($allowed);
		$session = $this->createMock(\Gt\Session\Session::class);
		$session->expects(self::never())->method("kill");
		$sut = new AdminArea($login, $users, $access, $this->createStub(ApplicationRepository::class));

		$this->expectException(AdminAccessDenied::class);
		$sut->logout($session);
	}

	private function deployment():ApplicationDeployment {
		return new ApplicationDeployment("provider", new Application("provider", "Provider", "a@b.test"),
			"Provider", "secret", "app.test", "/");
	}

	#[DataProvider("hosts")]
	public function testEnter_freshSessionUsesProviderHostAndRequestsAdminLogin(string $host, string $expected):void {
		$deployment = $this->deployment();
		$login = $this->createMock(LoginSession::class);
		$login->expects(self::once())->method("setDeploymentForLogin")->with($deployment);
		$login->expects(self::once())->method("requestAdmin");
		$login->method("getState")->willReturn(LoginState::NOT_LOGGED_IN);
		$applications = $this->createMock(ApplicationRepository::class);
		$applications->expects(self::once())->method("getDeploymentByProviderHost")
			->with($expected)->willReturn($deployment);
		$users = $this->createMock(UserRepository::class);
		$users->expects(self::never())->method("get");
		$response = $this->createMock(Response::class);
		$response->expects(self::once())->method("redirect")->with("/login/");
		$sut = new AdminArea($login, $users, $this->createStub(AdminAccess::class),
			$applications);
		$sut->enter(new Uri($host . "/admin/"), $response);
	}

	public static function hosts():iterable {
		yield ["https://provider.test", "provider.test"];
		yield ["https://provider.test:443", "provider.test"];
		yield ["http://localhost:9105", "localhost:9105"];
	}

	#[DataProvider("destinations")]
	public function testEnter_authorisedNavigationUsesRealApplicationCount(int $count, string $path, ?string $target):void {
		$deployment = $this->deployment();
		$user = new User("administrator", $deployment, "admin@example.test");
		$login = $this->createMock(LoginSession::class);
		$login->method("getDeployment")->willReturn($deployment);
		$login->method("getState")->willReturn(LoginState::LOGGED_IN);
		$login->method("getEmail")->willReturn($user->email);
		$login->expects(self::once())->method("clearAdminRequest");
		$users = $this->createStub(UserRepository::class);
		$users->method("get")->willReturn($user);
		$access = $this->createStub(AdminAccess::class);
		$access->method("allows")->willReturn(true);
		$registry = $this->createMock(ApplicationRepository::class);
		$registry->expects(self::once())->method("countFor")->with($user)->willReturn($count);
		$response = $this->createMock(Response::class);
		if($target === null) {
			$response->expects(self::never())->method("redirect");
		}
		else {
			$response->expects(self::once())->method("redirect")->with(self::callback(
				fn($uri) => (string)$uri === $target,
			));
		}
		$sut = new AdminArea($login, $users, $access,
			$registry);
		$sut->enter(new Uri($path), $response);
		self::assertSame($count === 0, $sut->onboarding()->needsApplication());
		self::assertSame($sut->onboarding(), $sut->onboarding());
	}

	public static function destinations():iterable {
		yield [0, "/admin/", "/admin/applications/#first-application"];
		yield [0, "/admin/applications/", null];
		yield [0, "/integrations/help/", null];
		yield [1, "/admin/", null];
	}

	#[DataProvider("unauthorised")]
	public function testOnboarding_missingOrUnauthorisedIdentityCannotLoadOnboarding(
		bool $loggedIn, bool $hasDeployment, ?string $email, bool $hasUser, bool $allowed,
	):void {
		$deployment = $this->deployment();
		$login = $this->createStub(LoginSession::class);
		$login->method("getDeployment")->willReturn($hasDeployment ? $deployment : null);
		$login->method("getState")->willReturn($loggedIn ? LoginState::LOGGED_IN : LoginState::NOT_LOGGED_IN);
		$login->method("getEmail")->willReturn($email);
		$users = $this->createStub(UserRepository::class);
		$users->method("get")->willReturn($hasUser ? new User("admin", $deployment, "a@b.test") : null);
		$access = $this->createStub(AdminAccess::class);
		$access->method("allows")->willReturn($allowed);
		$registry = $this->createMock(ApplicationRepository::class);
		$registry->expects(self::never())->method("countFor");
		$sut = new AdminArea($login, $users, $access,
			$registry);
		$this->expectException(AdminAccessDenied::class);
		$sut->onboarding();
	}

	public static function unauthorised():iterable {
		yield [false, true, "a@b.test", true, true];
		yield [true, false, "a@b.test", true, true];
		yield [true, true, null, true, true];
		yield [true, true, "", true, true];
		yield [true, true, "a@b.test", false, true];
		yield [true, true, "a@b.test", true, false];
	}
}
