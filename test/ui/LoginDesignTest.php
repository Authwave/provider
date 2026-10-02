<?php
namespace Authwave\Test\UI;

require_once __DIR__ . "/View.php";

use Authwave\Model\{Application, ApplicationDeployment};
use Authwave\Security\{AnonUser, Audit};
use Authwave\Session\{FlashSession, LoginSession};
use Authwave\User\{LoginState, User, UserRepository};
use GT\Cipher\{InitVector, Key};
use GT\Cipher\Message\EncryptedMessage;
use GT\Http\{Request, Response};
use GT\Input\Input;
use GT\Routing\LogicStream\{LogicStreamNamespace, LogicStreamWrapper};
use GT\Session\{Session, SessionStore};
use GT\WebEngine\Logic\LogicStreamHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Redirect extends RuntimeException {}

class LoginDesignTest extends TestCase {
	private ApplicationDeployment $deployment;
	private LoginSession $login;
	private FlashSession $flash;
	private Audit $audit;
	private AnonUser $anonymous;
	private UserRepository $users;
	private Session $session;
	private Response $response;
	private User $user;

	protected function setUp():void {
		chdir(dirname(__DIR__, 2));
		(new LogicStreamHandler())->setup();
		$this->deployment = new ApplicationDeployment("test-deployment",
			new Application("ui-test-no-logo", "Test app", "test@example.test"),
			"Test application", random_bytes(32), "client.example.test", "/callback");
		$this->audit = $this->createMock(Audit::class);
		$this->anonymous = $this->createMock(AnonUser::class);
		$this->session = $this->createMock(Session::class);
		$this->login = new LoginSession(new SessionStore("login", $this->session), $this->audit, $this->anonymous);
		$this->login->setDeploymentForLogin($this->deployment);
		$this->login->setEmail("test@example.test");
		$this->flash = new FlashSession(new SessionStore("flash", $this->session));
		$this->users = $this->createMock(UserRepository::class);
		$this->user = new User("test-user", $this->deployment, "test@example.test");
		$this->response = $this->createMock(Response::class);
		$this->response->method("redirect")->willReturnCallback(static function($uri):void {
			throw new Redirect((string)$uri);
		});
		$this->response->method("reload")->willReturnCallback(static function():void {
			throw new Redirect("reload");
		});
	}

	private function call(string $page, string $action, mixed ...$args):void {
		$file = "page/login/$page.php";
		require_once LogicStreamWrapper::STREAM_NAME . "://$file";
		$function = LogicStreamWrapper::NAMESPACE_PREFIX . new LogicStreamNamespace($file) . "\\$action";
		$function(...$args);
	}

	private function redirects(string $uri, callable $action):void {
		try {
			$action();
			self::fail("Expected redirect to $uri");
		}
		catch(Redirect $redirect) {
			self::assertSame($uri, $redirect->getMessage());
		}
	}

	public function testEmailAndBrandingBindingsAndContinue():void {
		$view = new View("login/index");
		$this->call("_common", "go", $view->document, $view->binder, $this->login);
		$request = $this->createMock(Request::class);
		$request->method("getMethod")->willReturn("GET");
		$this->call("index", "go", new Input(["email" => "edit@example.test"]), $request, $this->response, $this->login, $view->binder);
		self::assertSame("Test application", $view->document->querySelector("h1 span")->textContent);
		self::assertSame("/asset/default-logo.svg", $view->document->querySelector(".logo")->getAttribute("src"));
		self::assertSame("edit@example.test", $view->document->querySelector("input[name=email]")->getAttribute("value"));
		self::assertNull($this->login->getEmail());
		$this->redirects("/login/authenticate/", fn() => $this->call("index", "do_continue", new Input([], ["email" => "edit@example.test"]), $this->response, $this->login, $this->audit, $this->anonymous));
		self::assertSame("edit@example.test", $this->login->getEmail());
	}

	/** @dataProvider authenticationCases */
	public function testAuthenticationActions(string $action, bool $existing, bool $correctPassword, string $password):void {
		$view = new View("login/authenticate");
		self::assertNotNull($view->document->querySelector("button[name=do][value=$action]"));
		self::assertNotNull($view->document->querySelector("input[name=password]"));
		$this->call("authenticate", "go", $this->response, $this->login, $view->binder);
		self::assertSame("test@example.test", $view->document->querySelector(".auth-heading p a")->textContent);
		$this->users->method("get")->willReturn($existing ? $this->user : null);
		$usesPassword = $action === "password" || $password !== "";
		$this->users->expects($existing && $usesPassword ? self::once() : self::never())->method("checkLogin")
			->with($this->user, $password)->willReturn($correctPassword);
		$success = $existing && $usesPassword && $correctPassword;
		$args = [$this->deployment, $existing ? $this->user->id : "test@example.test"];
		if($usesPassword) { $args[] = $password; }
		$this->users->expects(!$success && $existing ? self::once() : self::never())->method("generateAuthCode")->with(...$args);
		$this->users->expects(!$existing ? self::once() : self::never())->method("create")->with(...$args);
		$this->redirects($success ? "/login/success/" : "/login/security-check/", fn() => $this->call("authenticate", "do_$action", new Input([], ["password" => $password]), $this->users, $this->login, $this->response));
		self::assertSame($success ? LoginState::LOGGED_IN : LoginState::NOT_LOGGED_IN, $this->login->getState());
	}

	public function authenticationCases():array {
		return [
			"password login" => ["password", true, true, "test-password"],
			"password reset" => ["password", true, false, "new-password!"],
			"password signup" => ["password", false, false, "new-password!"],
			"email login" => ["link", true, false, ""],
			"email signup" => ["link", false, false, ""],
			"email action with password" => ["link", true, false, "new-password!"],
		];
	}

	/** @dataProvider confirmationCases */
	public function testConfirmationAndErrorRendering(bool $valid):void {
		$view = new View("login/security-check");
		$input = $view->document->querySelector("security-code input[name=token]");
		self::assertNotNull($input);
		self::assertSame("[0-9]{5}", $input->getAttribute("pattern"));
		self::assertNotNull($view->document->querySelector("button[name=do][value=confirm]"));
		$this->users->method("get")->willReturn($this->user);
		$this->users->expects(self::once())->method("checkAuthCode")->with($this->user, "01234")->willReturn($valid);
		$this->redirects($valid ? "/login/success/" : "reload", fn() => $this->call("security-check", "do_confirm", new Input([], [$input->name => "01234"]), $this->response, $this->login, $this->flash, $this->users, $this->audit, $this->anonymous));
		$this->call("security-check", "go", $this->login, $this->flash, $view->document, $view->binder, $view->lists, $this->users);
		self::assertSame($valid ? LoginState::LOGGED_IN : LoginState::NOT_LOGGED_IN, $this->login->getState());
		$error = $view->document->querySelector("[role=alert]");
		if($valid) { self::assertNull($error); }
		else { self::assertSame("The code you entered is incorrect.", $error->textContent); }
	}

	public function confirmationCases():array { return [[true], [false]]; }

	public function testCancelReturnsToClient():void {
		$view = new View("login/security-check");
		self::assertNotNull($view->document->querySelector('a[href="/login/?do=cancel"]'));
		$this->session->expects(self::once())->method("kill");
		$this->redirects("https://client.example.test/callback?do=cancel", fn() => $this->call("index", "do_cancel", $this->response, $this->session, $this->login, $this->audit, $this->anonymous));
	}

	public function testSuccessRendersEncryptedClientReturn():void {
		$view = new View("login/success");
		$iv = new InitVector();
		$this->login->setState(LoginState::LOGGED_IN);
		$this->login->setData(["secretIv" => (string)$iv, "returnQuery" => "next=account"]);
		$this->users->method("get")->willReturn($this->user);
		$this->session->expects(self::once())->method("kill");
		$this->call("_common", "go", $view->document, $view->binder, $this->login);
		$this->call("success", "go", new Input(["debug" => "1"]), $this->response, $view->binder, $this->login, $this->users, $this->session, $this->audit);
		$uri = $view->document->querySelector("main p a")->getAttribute("href");
		self::assertStringStartsWith("https://client.example.test/callback?", $uri);
		parse_str(parse_url($uri, PHP_URL_QUERY), $query);
		self::assertSame("account", $query["next"]);
		$data = (new EncryptedMessage($query["AUTHWAVE_RESPONSE_DATA"], $iv))->decrypt(new Key($this->deployment->secret));
		self::assertSame(["id" => "test-user", "email" => "test@example.test"], json_decode((string)$data, true));
	}
}
