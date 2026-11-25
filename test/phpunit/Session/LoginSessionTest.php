<?php

namespace Authwave\Test\Session;

use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\Security\Action;
use Authwave\Security\Audit;
use Authwave\Security\AnonUser;
use Authwave\Session\LoginSession;
use Authwave\User\LoginState;
use GT\Session\SessionStore;
use GT\Session\Session;
use PHPUnit\Framework\TestCase;

class LoginSessionTest extends TestCase {
	public function testResetAuthentication_discardsTheCompletedClientHandoff():void {
		$store = new SessionStore("login", $this->createStub(Session::class));
		$sut = new LoginSession($store, $this->createStub(Audit::class), new AnonUser("anonymous"));
		$sut->setState(LoginState::LOGGED_IN);
		$client = new \Authwave\Model\UserSession("managed", "user", new \DateTimeImmutable(),
			new \DateTimeImmutable("+30 minutes"));
		$sut->setClientSession($client);
		self::assertSame($client, $sut->getClientSession());

		$sut->resetAuthentication();

		self::assertNull($sut->getClientSession());
	}

	public function testSetEmail_normalizesBeforeStoringLoginIdentity():void {
		$store = new SessionStore("login", $this->createStub(Session::class));
		$app = new Application("app", "Example", "sender@example.com");
		$store->set(
			"deployment",
			new ApplicationDeployment(
				"deployment",
				$app,
				"Example",
				"secret",
				"client.example.com",
				"/",
				"login.example.com"
			)
		);

		$sut = new LoginSession($store, $this->createStub(Audit::class), new AnonUser("anonymous"));
		$sut->setEmail(" ..test.user...@example.com ");

		self::assertSame("test.user@example.com", $sut->getEmail());
	}

	public function testSetState_authenticationStateChangeIsAuditedBeforeChangingSessionAndOnlyOnce():void {
		$store = new SessionStore("login", $this->createStub(Session::class));
		$audit = $this->createMock(Audit::class);
		$audit->expects(self::once())->method("recordChange")->with(Action::AUTHENTICATION_STATE_CHANGED,
			self::callback(function(object $before)use($store):bool {
				self::assertNull($store->get(LoginState::class));
				return $before->state === "NOT_LOGGED_IN";
			}), (object)["state" => "LOGGED_IN"], self::isInstanceOf(AnonUser::class));
		$sut = new LoginSession($store, $audit, new AnonUser("anonymous"));
		$sut->setState(LoginState::LOGGED_IN);
		$sut->setState(LoginState::LOGGED_IN);
		self::assertSame(LoginState::LOGGED_IN, $sut->getState());
	}

	public function testSetState_leavingAuthenticationDiscardsSudoAuthorisation():void {
		$store = new SessionStore("login", $this->createStub(Session::class));
		$store->set(LoginState::class, LoginState::LOGGED_IN);
		$store->getStore("sudo", true)->set("until", time() + 60);
		$sut = new LoginSession($store, $this->createStub(Audit::class), new AnonUser("anonymous"));

		$sut->setState(LoginState::NOT_LOGGED_IN);

		self::assertNull($store->getStore("sudo"));
	}

	public function testSetState_auditFailureDoesNotAuthenticateTheSession():void {
		$store = new SessionStore("login", $this->createStub(Session::class));
		$audit = $this->createStub(Audit::class);
		$audit->method("recordChange")->willThrowException(new \RuntimeException("Audit failed"));
		$sut = new LoginSession($store, $audit, new AnonUser("anonymous"));
		try {
			$sut->setState(LoginState::LOGGED_IN);
			self::fail("Expected audit failure");
		}
		catch(\RuntimeException) {}
		self::assertSame(LoginState::NOT_LOGGED_IN, $sut->getState());
	}
}
