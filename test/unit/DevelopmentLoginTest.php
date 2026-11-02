<?php
namespace Authwave\Test;

use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\Model\ApplicationRepository;
use Authwave\Security\AnonUser;
use Authwave\Security\Audit;
use Authwave\Session\LoginSession;
use Authwave\User\LoginState;
use Gt\Config\Config;
use Gt\Config\ConfigSection;
use Gt\Http\Response;
use Gt\Http\ResponseStatusException\ClientError\HttpNotFound;
use Gt\Http\Uri;
use Gt\Session\Session;
use Gt\Session\SessionStore;
use PHPUnit\Framework\TestCase;

class DevelopmentLoginTest extends TestCase {
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider modes
	 */
	public function testDevelopmentLogin(bool $enabled, bool $production):void {
		require dirname(__DIR__, 2) . "/page/_common.php";
		$config = new Config(
			new ConfigSection("authwave", ["dev_login" => $enabled ? "1" : "0"]),
			new ConfigSection("app", ["production" => $production ? "1" : "0"]),
		);
		$session = $this->createMock(Session::class);
		$store = new SessionStore("login", $session);
		$login = new LoginSession($store, $this->createMock(Audit::class), new AnonUser("visitor"));
		$store->set("email", "old@example.test");
		$login->setState(LoginState::LOGGED_IN);
		$login->setData(["returnUri" => "https://old.example.test/"]);
		$apps = $this->createMock(ApplicationRepository::class);
		$response = $this->createMock(Response::class);
		if(!$enabled || $production) {
			$apps->expects(self::never())->method("getDeploymentById");
			$response->expects(self::never())->method("redirect");
			$this->expectException(HttpNotFound::class);
		}
		else {
			$deployment = new ApplicationDeployment("dev", new Application("app", "Test", "sender@example.test"), "Test", str_repeat("s", 32), "https://app.example.test", "/callback");
			$apps->expects(self::once())->method("getDeploymentById")->with("dev")->willReturn($deployment);
			$response->expects(self::once())->method("redirect")->with("/login/");
		}
		\go($apps, new Uri("http://localhost/?dev-login=dev"), $login, $session, $response, $config);
		self::assertSame($deployment, $login->getDeployment());
		self::assertNull($login->getEmail());
		self::assertSame(LoginState::NOT_LOGGED_IN, $login->getState());
		self::assertNull($login->getDataKey("returnUri"));
		self::assertSame("1", $login->getDataKey("developmentLogin"));
		self::assertSame(SODIUM_CRYPTO_BOX_NONCEBYTES, strlen(base64_decode($login->getDataKey("secretIv"), true)));
	}

	public function modes():array {
		return [[false, false], [true, true], [true, false]];
	}
}
