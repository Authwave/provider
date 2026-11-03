<?php

namespace Authwave\Test\Integration;

use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\Model\ApplicationRepository;
use Authwave\Security\AnonUser;
use Authwave\Security\Audit;
use Authwave\Session\LoginSession;
use GT\Cipher\InitVector;
use GT\Cipher\Key;
use GT\Cipher\Message\PlainTextMessage;
use Gt\Http\Response;
use Gt\Http\Uri;
use Gt\Session\Session;
use Gt\Session\SessionStore;
use PHPUnit\Framework\TestCase;

class LoginRequestReturnUriTest extends TestCase {
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
	#[\PHPUnit\Framework\Attributes\DataProvider("destinations")]
	public function testLoginSavesValidatedDestination(?string $requested, ?string $expected):void {
		require dirname(__DIR__, 2) . "/page/_common.php";
		$secret = str_repeat("s", 32);
		$deployment = new ApplicationDeployment(
			"deployment", new Application("app", "Example", "sender@example.test"),
			"Example", $secret, "https://app.example.test", "/callback",
		);
		$session = $this->createStub(Session::class);
		$login = new LoginSession(new SessionStore("login", $session), $this->createStub(Audit::class), new AnonUser("visitor"));
		$apps = $this->createMock(ApplicationRepository::class);
		$apps->expects(self::once())->method("getDeploymentById")->with("deployment")->willReturn($deployment);
		$iv = new InitVector();
		$cipher = (new PlainTextMessage(http_build_query([
			"action" => "login", "secretIv" => (string)new InitVector(),
			// A caller cannot bypass origin validation through the encrypted payload.
			"returnUri" => "https://evil.example/steal",
		]), $iv))->encrypt(new Key($secret));
		$query = ["c" => (string)$cipher, "i" => (string)$iv, "d" => "deployment"];
		if(!is_null($requested)) {
			$query["u"] = bin2hex($requested);
		}
		$response = $this->createMock(Response::class);
		$response->expects(self::once())->method("redirect")->with("/");

		\go($apps, new Uri("https://login.example.test/?" . http_build_query($query)), $login, $session, $response, new \Gt\Config\Config(), new \GT\Dom\HTMLDocument("<html><body></body></html>"), $this->createStub(\Authwave\Security\Audit::class), new \Authwave\Security\AnonUser("visitor"));

		self::assertSame($expected, $login->getDataKey("returnUri"));
		self::assertSame($deployment, $login->getDeployment());
		self::assertNotEmpty($login->getDataKey("secretIv"));
	}

	public static function destinations():array {
		return [
			["/members?status=open#latest", "https://app.example.test/members?status=open#latest"],
			["https://app.example.test/members", "https://app.example.test/members"],
			["https://evil.example/steal", null],
			[null, null],
		];
	}
}
