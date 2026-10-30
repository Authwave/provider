<?php
namespace Authwave\Test;

use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\Model\ApplicationRepository;
use Authwave\Security\AnonUser;
use Authwave\Security\Audit;
use Authwave\Session\LoginSession;
use Gt\Cipher\InitVector;
use Gt\Cipher\Key;
use Gt\Cipher\Message\PlainTextMessage;
use Gt\Http\Response;
use Gt\Http\Uri;
use Gt\Session\Session;
use Gt\Session\SessionStore;
use PHPUnit\Framework\TestCase;

class LoginRequestReturnUriTest extends TestCase {
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider destinations
	 */
	public function testLoginSavesValidatedDestination(?string $requested, ?string $expected):void {
		require dirname(__DIR__, 2) . "/page/_common.php";
		$secret = str_repeat("s", 32);
		$deployment = new ApplicationDeployment(
			"deployment", new Application("app", "Example", "sender@example.test"),
			"Example", $secret, "https://app.example.test", "/callback",
		);
		$session = $this->createMock(Session::class);
		$login = new LoginSession(new SessionStore("login", $session), $this->createMock(Audit::class), new AnonUser("visitor"));
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

		\go($apps, new Uri("https://login.example.test/?" . http_build_query($query)), $login, $session, $response);

		self::assertSame($expected, $login->getDataKey("returnUri"));
		self::assertSame($deployment, $login->getDeployment());
		self::assertNotEmpty($login->getDataKey("secretIv"));
	}

	public function destinations():array {
		return [
			["/members?status=open#latest", "https://app.example.test/members?status=open#latest"],
			["https://app.example.test/members", "https://app.example.test/members"],
			["https://evil.example/steal", null],
			[null, null],
		];
	}
}
