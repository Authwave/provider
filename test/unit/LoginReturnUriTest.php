<?php
namespace Authwave\Test;

use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\Session\LoginSession;
use Authwave\Security\Audit;
use Authwave\Security\AdminAccess;
use Authwave\User\LoginState;
use Authwave\User\User;
use Authwave\User\UserRepository;
use Gt\Cipher\InitVector;
use Gt\Cipher\Key;
use Gt\Cipher\Message\EncryptedMessage;
use Gt\DomTemplate\Binder;
use Gt\Http\Response;
use Gt\Http\Request;
use Gt\Input\Input;
use Gt\Session\Session;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;

class LoginReturnUriTest extends TestCase {
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider destinations
	 */
	public function testLoginReturnsEncryptedUserToRequestedPathQueryAndFragment(
		?string $requested,
		?string $legacyQuery,
		string $expected,
	):void {
		require dirname(__DIR__, 2) . "/page/login/success.php";
		$secret = str_repeat("s", 32);
		$iv = new InitVector();
		$deployment = new ApplicationDeployment(
			"deployment", new Application("app", "Example", "sender@example.test"),
			"Example", $secret, "https://app.example.test", "/callback",
		);
		$user = new User("user-id", $deployment, "person@example.test");
		$login = $this->createMock(LoginSession::class);
		$login->method("getState")->willReturn(LoginState::LOGGED_IN);
		$login->method("getDeployment")->willReturn($deployment);
		$login->method("getEmail")->willReturn($user->email);
		$login->method("getDataKey")->willReturnCallback(fn($key) => [
			"secretIv" => (string)$iv,
			"returnUri" => $requested,
			"returnQuery" => $legacyQuery,
		][$key] ?? null);
		$users = $this->createMock(UserRepository::class);
		$users->method("get")->willReturn($user);
		$response = $this->createMock(Response::class);
		$response->expects(self::once())->method("redirect")
			->willReturnCallback(function(UriInterface $uri) use ($secret, $iv, $expected):void {
				self::assertSame($expected, (string)(new \Gt\Http\Uri((string)$uri))->withoutQueryValue("AUTHWAVE_RESPONSE_DATA"));
				parse_str($uri->getQuery(), $query);
				$decoded = (new EncryptedMessage($query["AUTHWAVE_RESPONSE_DATA"], $iv))->decrypt(new Key($secret));
				self::assertSame("user-id", json_decode((string)$decoded)->id);
			});
		$session = $this->createMock(Session::class);
		$session->expects(self::once())->method("kill");
		\go(
			$this->createMock(Input::class), $response, $this->createMock(Binder::class),
			$login, $users, $session, $this->createMock(Audit::class),
			$this->createMock(AdminAccess::class), $this->createMock(Request::class),
		);
	}

	public function destinations():array {
		return [
			["https://app.example.test/members/orders?status=open#latest", "status=open", "https://app.example.test/members/orders?status=open#latest"],
			["https://app.example.test/members", "stale=query", "https://app.example.test/members"],
			[null, null, "https://app.example.test/callback"],
			[null, "page=2", "https://app.example.test/callback?page=2"],
		];
	}
}
