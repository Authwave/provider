<?php

namespace Authwave\Test\Integration;

use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\Model\ApplicationRepository;
use Authwave\Security\AnonUser;
use Authwave\Security\Audit;
use Authwave\Session\LoginSession;
use Authwave\User\LoginState;
use GT\Config\Config;
use GT\Config\ConfigSection;
use GT\Http\Response;
use GT\Http\ResponseStatusException\ClientError\HttpNotFound;
use GT\Http\Uri;
use GT\Session\Session;
use GT\Session\SessionStore;
use PHPUnit\Framework\TestCase;

class DevelopmentLoginTest extends TestCase {
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
	#[\PHPUnit\Framework\Attributes\DataProvider("modes")]
	public function testDevelopmentLogin(bool $production):void {
		require dirname(__DIR__, 2) . "/page/_common.php";

		$config = new Config(
			new ConfigSection("app", ["production" => $production ? "1" : "0"]),
		);
		$session = $this->createStub(Session::class);
		$store = new SessionStore("login", $session);
		$login = new LoginSession($store, $this->createStub(Audit::class), new AnonUser("visitor"), $this->createStub(\GT\Cookie\CookieHandler::class));
		$store->set("email", "old@example.test");
		$login->setState(LoginState::LOGGED_IN);
		$login->setData(["returnUri" => "https://old.example.test/"]);
		$apps = $this->createMock(ApplicationRepository::class);
		$response = $this->createMock(Response::class);

		if($production) {
			$apps->expects(self::never())->method("getDeploymentById");
			$response->expects(self::never())->method("redirect");
			$this->expectException(HttpNotFound::class);
		}
		else {
			$deployment = new ApplicationDeployment("dev", new Application("app", "Test", "sender@example.test"), "Test", str_repeat("s", 32), "https://app.example.test", "/callback");
			$apps->expects(self::once())->method("getDeploymentById")->with("dev")->willReturn($deployment);
			$response->expects(self::once())->method("redirect")->with("/login/");
		}

		\go($apps, $this->createStub(\Authwave\User\UserRepository::class), new Uri("http://localhost/?dev-login=dev"), $login, $session, $response,
			$config, new \GT\Dom\HTMLDocument("<html><body></body></html>"),
			new AnonUser("visitor"), $this->createStub(\GT\Cookie\CookieHandler::class));

		self::assertSame($deployment, $login->getDeployment());
		self::assertNull($login->getEmail());
		self::assertSame(LoginState::NOT_LOGGED_IN, $login->getState());
		self::assertNull($login->getDataKey("returnUri"));
		self::assertSame("1", $login->getDataKey("developmentLogin"));
		self::assertSame(SODIUM_CRYPTO_BOX_NONCEBYTES, strlen(base64_decode($login->getDataKey("secretIv"), true)));
	}

	public static function modes():array {
		return [[false], [true]];
	}
}
