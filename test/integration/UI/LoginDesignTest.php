<?php

namespace Authwave\Test\Integration\UI;

use Authwave\Model\ApplicationRepository;
use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\Model\ApplicationTheme;
use Authwave\Security\AnonUser;
use Authwave\Security\Audit;
use Authwave\Security\AdminAccess;
use Authwave\Session\FlashSession;
use Authwave\Session\LoginSession;
use Authwave\User\LoginState;
use Authwave\Model\User;
use Authwave\User\UserRepository;
use GT\Cipher\InitVector;
use GT\Cipher\Key;
use GT\Cipher\Message\EncryptedMessage;
use GT\Http\Request;
use GT\Http\Response;
use GT\Input\Input;
use GT\Routing\LogicStream\LogicStreamNamespace;
use GT\Routing\LogicStream\LogicStreamWrapper;
use GT\Session\Session;
use GT\Session\SessionStore;
use GT\WebEngine\Logic\LogicStreamHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Authwave\Test\UI\View;

require_once dirname(__DIR__, 2) . "/phpunit/UI/View.php";
class Redirect extends RuntimeException
{
}

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
		chdir(dirname(__DIR__, 3));
		(new LogicStreamHandler())->setup();
		$this->deployment = new ApplicationDeployment("test-deployment",
			new Application("ui-test-no-logo", "Test app", "test@example.test"),
			"Test application", random_bytes(32), "client.example.test", "/callback");
		$this->audit = $this->createStub(Audit::class);
		$this->anonymous = $this->createStub(AnonUser::class);
		$this->session = $this->createStub(Session::class);
		$this->login = new LoginSession(new SessionStore("login", $this->session), $this->audit, $this->anonymous);
		$this->login->setDeploymentForLogin($this->deployment);
		$this->login->setEmail("test@example.test");
		$this->flash = new FlashSession(new SessionStore("flash", $this->session));
		$this->users = $this->createStub(UserRepository::class);
		$this->user = new User("test-user", $this->deployment, "test@example.test");
		$this->users->method("startSession")->willReturn(new \Authwave\Model\UserSession("session", $this->user->id,
			new \DateTimeImmutable(), new \DateTimeImmutable("+30 minutes")));
		$this->response = $this->createStub(Response::class);
		$this->response->method("redirect")->willReturnCallback(static function($uri):void {
			throw new Redirect((string)$uri);
		});
		$this->response->method("reload")->willReturnCallback(static function():void {
			throw new Redirect("reload");
		});
	}

	private function call(string $page, string $action, mixed ...$args):void {
		$file = "page/login/$page.php";
		$this->callFile($file, $action, ...$args);
	}

	private function callFile(string $file, string $action, mixed ...$args):void {
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
		$request = $this->createStub(Request::class);
		$request->method("getMethod")->willReturn("GET");
		$this->call("index", "go", new Input(["email" => "edit@example.test"]), $request, $this->response, $this->login, $view->binder);
		self::assertSame("Test app", $view->document->querySelector("h1 span")->textContent);
		self::assertSame("/asset/default-logo.svg", $view->document->querySelector(".logo")->getAttribute("src"));
		self::assertSame("edit@example.test", $view->document->querySelector("input[name=email]")->getAttribute("value"));
		self::assertNull($this->login->getEmail());
		$this->redirects("/login/authenticate/", fn() => $this->call("index", "do_continue", new Input([], ["email" => "edit@example.test"]), $this->response, $this->login, $this->audit, $this->anonymous));
		self::assertSame("edit@example.test", $this->login->getEmail());
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("brandingCases")]
	public function testApplicationBranding(string $page, array $files, ?string $colour, string $light, string $dark, ?string $style, ?string $secondaryColour = null):void {
		$id = "ui-branding-" . bin2hex(random_bytes(8));
		$directory = "data/upload/$id";
		mkdir($directory, 0777, true);
		try {
			foreach($files as $file) {
				file_put_contents("$directory/$file", "test image");
			}
			$deployment = new ApplicationDeployment("test-deployment",
				new Application($id, "Branded app", "test@example.test", themes: [new ApplicationTheme("light", ["primary" => $colour, "secondary" => $secondaryColour], "app")]),
				"Branded application", random_bytes(32), "client.example.test", "/callback");
			$this->login->setDeploymentForLogin($deployment);
			$view = new View("login/$page");
			$this->call("_common", "go", $view->document, $view->binder, $this->login);
			$picture = $view->document->querySelector("picture");
			$source = $picture->querySelector("source");
			self::assertSame("(prefers-color-scheme: dark)", $source->getAttribute("media"));
			self::assertSame($dark ? "/$directory/$dark" : "/asset/default-logo.svg", $source->getAttribute("srcset"));
			self::assertSame($light ? "/$directory/$light" : "/asset/default-logo.svg", $picture->querySelector("img")->getAttribute("src"));
			self::assertSame("Branded app logo", $picture->querySelector("img")->getAttribute("alt"));
			self::assertNull($view->document->documentElement->getAttribute("style"));
			self::assertSame($style ? ':root[data-flair-theme="bright"][data-color-scheme="light"] { ' . $style . ' }' : null,
				$view->document->querySelector("#application-theme")?->textContent);
		}
		finally {
			foreach($files as $file) {
				unlink("$directory/$file");
			}
			rmdir($directory);
		}
	}

	public static function brandingCases():array {
		return [
			"default branding" => ["index", [], null, "", "", null],
			"SVG pair" => ["index", ["logo.svg", "logo_dark.svg"], "#123456", "logo.svg", "logo_dark.svg", "--theme-color-primary: #123456;"],
			"mixed formats" => ["authenticate", ["logo.png", "logo_dark.jpeg"], "#abc", "logo.png", "logo_dark.jpeg", "--theme-color-primary: #abc;"],
			"light only" => ["security-check", ["logo.jpg"], null, "logo.jpg", "logo.jpg", null],
			"dark only" => ["success", ["logo_dark.PNG"], "#11223344", "", "logo_dark.PNG", "--theme-color-primary: #11223344;"],
			"invalid colour and unrelated files" => ["index", ["logo.txt", "logo_old.svg"], "red; display: none", "", "", null],
			"both colours" => ["index", [], "#123456", "", "", "--theme-color-primary: #123456; --theme-color-secondary: #abcdef;", "#abcdef"],
			"secondary only" => ["authenticate", [], null, "", "", "--theme-color-secondary: #abcd;", "#abcd"],
			"invalid secondary" => ["security-check", [], "#abc", "", "", "--theme-color-primary: #abc;", "red; display: none"],
			"invalid primary with secondary" => ["success", [], "invalid", "", "", "--theme-color-secondary: #12345678;", "#12345678"],
		];
	}

	public function testLightAndDarkThemesRenderAfterTheDefaultStylesheet():void {
		$this->login->setDeploymentForLogin(new ApplicationDeployment("test-deployment",
			new Application("ui-test-no-logo", "Test app", "test@example.test", themes: [
				new ApplicationTheme("light", ["primary" => "#123456"], "app"),
				new ApplicationTheme("dark", ["primary" => "#abcdef", "pageBackground" => "#111"], "app"),
			]), "Test application", random_bytes(32), "client.example.test", "/callback"));
		$view = new View("login/index");
		$this->call("_common", "go", $view->document, $view->binder, $this->login);
		self::assertSame(":root[data-flair-theme=\"bright\"][data-color-scheme=\"light\"] { --theme-color-primary: #123456; }\n:root[data-flair-theme=\"bright\"][data-color-scheme=\"dark\"] { --theme-color-primary: #abcdef; --theme-color-page: #111; }",
			$view->document->querySelector("#application-theme")->textContent);
		$html = (string)$view->document;
		self::assertGreaterThan(strpos($html, '/style.css'), strpos($html, 'id="application-theme"'));
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("authenticationCases")]
	public function testAuthenticationActions(string $action, bool $existing, bool $correctPassword, string $password):void {
		$this->users = $this->createMock(UserRepository::class);
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

	public static function authenticationCases():array {
		return [
			"password login" => ["password", true, true, "test-password"],
			"password reset" => ["password", true, false, "new-password!"],
			"password signup" => ["password", false, false, "new-password!"],
			"email login" => ["link", true, false, ""],
			"email signup" => ["link", false, false, ""],
			"email action with password" => ["link", true, false, "new-password!"],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("confirmationCases")]
	public function testConfirmationAndErrorRendering(bool $valid):void {
		$this->users = $this->createMock(UserRepository::class);
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

	public static function confirmationCases():array { return [[true], [false]]; }

	public function testCancelReturnsToClient():void {
		$this->session = $this->createMock(Session::class);
		$view = new View("login/security-check");
		self::assertNotNull($view->document->querySelector('a[href="/login/?do=cancel"]'));
		$this->session->expects(self::once())->method("kill");
		$this->redirects("https://client.example.test/callback", fn() => $this->call("index", "do_cancel", $this->response, $this->session, $this->login, $this->audit, $this->anonymous));
	}

	public function testSuccessRendersEncryptedClientReturn():void {
		$this->session = $this->createMock(Session::class);
		$view = new View("login/success");
		$iv = new InitVector();
		$this->login->setState(LoginState::LOGGED_IN);
		$this->login->setData(["secretIv" => (string)$iv, "returnQuery" => "next=account"]);
		$this->users->method("get")->willReturn($this->user);
		$this->session->expects(self::once())->method("kill");
		$this->call("_common", "go", $view->document, $view->binder, $this->login);
		$this->call("success", "go", new Input(["debug" => "1"]), $this->response, $view->binder, $this->login, $this->users, $this->session, $this->audit, new AdminAccess($this->createStub(\GT\Database\Query\QueryCollection::class), null), $this->createStub(Request::class), $this->createStub(ApplicationRepository::class), $this->createStub(\GT\Cookie\CookieHandler::class));
		$uri = $view->document->querySelector("main p a")->getAttribute("href");
		self::assertStringStartsWith("https://client.example.test/callback?", $uri);
		parse_str(parse_url($uri, PHP_URL_QUERY), $query);
		self::assertSame("account", $query["next"]);
		$data = (new EncryptedMessage($query["AUTHWAVE_RESPONSE_DATA"], $iv))->decrypt(new Key($this->deployment->secret));
		self::assertSame(["id" => "test-user", "email" => "test@example.test"], json_decode((string)$data, true));
	}

	public function testAdminSuccessOffersBothDestinationsAndRetainsSession():void {
		$this->session = $this->createMock(Session::class);
		$this->response = $this->createMock(Response::class);
		$view = new View("login/success");
		$this->login->setState(LoginState::LOGGED_IN);
		$this->login->setData(["secretIv" => (string)new InitVector()]);
		$this->users->method("get")->willReturn($this->user);
		$this->session->expects(self::never())->method("kill");
		$this->response->expects(self::never())->method("redirect");
		$this->call("_common", "go", $view->document, $view->binder, $this->login);
		$this->call("success", "go", new Input([]), $this->response, $view->binder, $this->login, $this->users, $this->session, $this->audit, new AdminAccess($this->createStub(\GT\Database\Query\QueryCollection::class), $this->user->email), $this->createStub(Request::class), $this->createStub(ApplicationRepository::class), $this->createStub(\GT\Cookie\CookieHandler::class));
		$link = $view->document->querySelector('a[href="/admin/"]');
		self::assertNotNull($link);
		self::assertFalse($link->parentElement->hasAttribute("hidden"));
		self::assertNull($view->document->querySelector("a[data-client-redirect]"));
		self::assertStringContainsString("AUTHWAVE_RESPONSE_DATA=", $view->document->querySelector("main p a")->getAttribute("href"));
	}

	public function testOrdinaryUserStillRedirectsAutomatically():void {
		$this->session = $this->createMock(Session::class);
		$view = new View("login/success");
		$this->login->setState(LoginState::LOGGED_IN);
		$this->login->setData(["secretIv" => (string)new InitVector()]);
		$this->users->method("get")->willReturn($this->user);
		$this->session->expects(self::once())->method("kill");
		try {
			$this->call("success", "go", new Input([]), $this->response, $view->binder, $this->login, $this->users, $this->session, $this->audit, new AdminAccess($this->createStub(\GT\Database\Query\QueryCollection::class), "someone-else@example.test"), $this->createStub(Request::class), $this->createStub(ApplicationRepository::class), $this->createStub(\GT\Cookie\CookieHandler::class));
			self::fail("Expected client redirect");
		}
		catch(Redirect $redirect) {
			self::assertStringStartsWith("https://client.example.test/callback?AUTHWAVE_RESPONSE_DATA=", $redirect->getMessage());
		}
		self::assertTrue($view->document->querySelector('a[href="/admin/"]')->parentElement->hasAttribute("hidden"));
	}

	public function testAdminDestinationSurvivesAuthenticationWithoutClientCipher():void {
		$this->session = $this->createMock(Session::class);
		$this->login->requestAdmin();
		$this->login->setState(LoginState::LOGGED_IN);
		$this->users->method("get")->willReturn($this->user);
		$this->session->expects(self::never())->method("kill");
		$view = new View("login/success");
		$this->redirects("/admin/", fn() => $this->call("success", "go", new Input([]), $this->response, $view->binder, $this->login, $this->users, $this->session, $this->audit, new AdminAccess($this->createStub(\GT\Database\Query\QueryCollection::class), $this->user->email), $this->createStub(Request::class), $this->createStub(ApplicationRepository::class), $this->createStub(\GT\Cookie\CookieHandler::class)));
	}

	public function testChangingEmailOrDeploymentRequiresAuthenticationAgain():void {
		$this->login->setState(LoginState::LOGGED_IN);
		$this->login->setEmail("admin@example.test");
		self::assertSame(LoginState::NOT_LOGGED_IN, $this->login->getState());
		$this->login->setState(LoginState::LOGGED_IN);
		$this->login->requestAdmin();
		$this->login->setDeploymentForLogin($this->deployment);
		self::assertSame(LoginState::NOT_LOGGED_IN, $this->login->getState());
		self::assertNull($this->login->getEmail());
		self::assertFalse($this->login->isAdminRequested());
	}

	public function testAdminAccessIsDeniedWithoutGrantOrConfiguredEmail():void {
		self::assertFalse((new AdminAccess($this->createStub(\GT\Database\Query\QueryCollection::class), null))->allows($this->user));
		self::assertFalse((new AdminAccess($this->createStub(\GT\Database\Query\QueryCollection::class), ""))->allows($this->user));
		self::assertFalse((new AdminAccess($this->createStub(\GT\Database\Query\QueryCollection::class), $this->user->email))->allows(null));
		self::assertTrue((new AdminAccess($this->createStub(\GT\Database\Query\QueryCollection::class), strtoupper($this->user->email)))->allows($this->user));
	}

	public function testAccessDeniedPageUsesLoginBrandingAndStartsClientHandshake():void {
		$this->login->setState(LoginState::LOGGED_IN);
		$this->login->requestAdmin();
		$view = new View("_error/403");
		$this->callFile("page/_error/_common.php", "go", $view->document, $view->binder, $this->login);
		self::assertTrue($view->document->body->classList->contains("dir--login"));
		self::assertSame("Test app logo", $view->document->querySelector(".logo")->getAttribute("alt"));
		self::assertStringContainsString("doesn't have access to user administration", $view->document->body->textContent);
		$link = $view->document->querySelector(".auth-heading a");
		self::assertSame("https://client.example.test/callback", $link->getAttribute("href"));
		self::assertSame("Continue to Test app", $link->textContent);
		self::assertSame("/login/access-denied/", $view->document->querySelector("form")->getAttribute("action"));
		self::assertFalse($this->login->isAdminRequested());
	}

	public function testSwitchAccountEndpointRequiresAuthentication():void {
		$this->redirects("/login/", fn() => $this->call("access-denied", "go", $this->login, $this->response));
		self::assertTrue($this->login->isAdminRequested());
	}

	public function testSwitchingAccountClearsIdentityAndRemembersAdminDestination():void {
		$this->login->setState(LoginState::LOGGED_IN);
		$request = $this->createStub(Request::class);
		$request->method("getMethod")->willReturn("POST");
		$request->method("getUri")->willReturn(new \GT\Http\Uri("https://login.example.test/login/access-denied/"));
		$this->redirects("/login/", fn() => $this->call("access-denied", "do_switch_account", $request, $this->login, $this->response));
		self::assertNull($this->login->getEmail());
		self::assertSame(LoginState::NOT_LOGGED_IN, $this->login->getState());
		self::assertTrue($this->login->isAdminRequested());
	}

	public function testSwitchingAccountCannotRunFromGetOrAdminErrorRendering():void {
		$this->response = $this->createMock(Response::class);
		$this->login->setState(LoginState::LOGGED_IN);
		$this->response->expects(self::never())->method("redirect");
		foreach([["GET", "/login/access-denied/"], ["POST", "/admin/"]] as [$method, $path]) {
			$request = $this->createStub(Request::class);
			$request->method("getMethod")->willReturn($method);
			$request->method("getUri")->willReturn(new \GT\Http\Uri("https://login.example.test$path"));
			$this->call("access-denied", "do_switch_account", $request, $this->login, $this->response);
			self::assertSame($this->user->email, $this->login->getEmail());
			self::assertSame(LoginState::LOGGED_IN, $this->login->getState());
		}
	}

	public function testFluxSuccessRendersBrowserHandoffWithoutCrossOriginFetch():void {
		$this->session = $this->createMock(Session::class);
		$this->response = $this->createMock(Response::class);
		$view = new View("login/success");
		$this->login->setState(LoginState::LOGGED_IN);
		$this->login->setData(["secretIv" => (string)new InitVector()]);
		$this->users->method("get")->willReturn($this->user);
		$this->session->expects(self::once())->method("kill");
		$this->response->expects(self::never())->method("redirect");
		$request = $this->createMock(Request::class);
		$request->expects(self::atLeastOnce())->method("getHeaderLine")->with("X-Authwave-Flux")->willReturn("1");
		$this->call("success", "go", new Input([]), $this->response, $view->binder, $this->login, $this->users, $this->session, $this->audit, new AdminAccess($this->createStub(\GT\Database\Query\QueryCollection::class)), $request, $this->createStub(ApplicationRepository::class), $this->createStub(\GT\Cookie\CookieHandler::class));
		$link = $view->document->querySelector("a[data-client-redirect]");
		self::assertNotNull($link);
		self::assertStringStartsWith("https://client.example.test/callback?AUTHWAVE_RESPONSE_DATA=", $link->getAttribute("href"));
	}

	public function testAdminLogoutClearsSessionAndUsesConfiguredDestination():void {
		$this->session = $this->createMock(Session::class);
		$this->login->setState(LoginState::LOGGED_IN);
		$this->users->method("get")->willReturn($this->user);
		$this->session->expects(self::once())->method("kill");
		$request = $this->createStub(Request::class);
		$request->method("getMethod")->willReturn("POST");
		$request->method("getUri")->willReturn(new \GT\Http\Uri("https://login.example.test/admin/logout/"));
		$config = $this->createMock(\GT\Config\Config::class);
		$config->expects(self::atLeastOnce())->method("getString")->with("authwave.logout_redirect")->willReturn("https://public.example.test/");
		$this->redirects("https://public.example.test/", fn() => $this->callFile("page/admin/logout.php", "do_logout", new \Authwave\Admin\Onboarding\AdminArea($this->login, $this->users, new AdminAccess($this->createStub(\GT\Database\Query\QueryCollection::class), $this->user->email), $this->createStub(\Authwave\Model\ApplicationRepository::class)), $request, $this->response, $this->session, $config, $this->createStub(\GT\Cookie\CookieHandler::class)));
		self::assertNull($this->login->getEmail());
		self::assertSame(LoginState::NOT_LOGGED_IN, $this->login->getState());
	}

	public function testAdminLogoutNeverRunsFromGetOrAnotherRoute():void {
		$this->session = $this->createMock(Session::class);
		$this->response = $this->createMock(Response::class);
		$this->login->setState(LoginState::LOGGED_IN);
		$this->session->expects(self::never())->method("kill");
		$this->response->expects(self::never())->method("redirect");
		foreach([["GET", "/admin/logout/"], ["POST", "/admin/security/"]] as [$method, $path]) {
			$request = $this->createStub(Request::class);
			$request->method("getMethod")->willReturn($method);
			$request->method("getUri")->willReturn(new \GT\Http\Uri("https://login.example.test$path"));
			$this->callFile("page/admin/logout.php", "do_logout", new \Authwave\Admin\Onboarding\AdminArea($this->login, $this->users, new AdminAccess($this->createStub(\GT\Database\Query\QueryCollection::class), $this->user->email), $this->createStub(\Authwave\Model\ApplicationRepository::class)), $request, $this->response, $this->session, new \GT\Config\Config(), $this->createStub(\GT\Cookie\CookieHandler::class));
			self::assertSame(LoginState::LOGGED_IN, $this->login->getState());
		}
	}

	public function testLoggedOutLandingDoesNotReinitialiseOrRedirectToClient():void {
		$this->response = $this->createMock(Response::class);
		$apps = $this->createMock(\Authwave\Model\ApplicationRepository::class);
		$apps->expects(self::never())->method("getDeploymentByProviderHost");
		$this->response->expects(self::never())->method("redirect");
		$this->callFile("page/_common.php", "go", $apps, $this->createStub(UserRepository::class), new \GT\Http\Uri("https://login.example.test/logged-out/"), new LoginSession(new SessionStore("empty", $this->session), $this->audit, $this->anonymous), $this->session, $this->response, new \GT\Config\Config(), new \GT\Dom\HTMLDocument("<html><body></body></html>"), new \Authwave\Security\AnonUser("visitor"), $this->createStub(\GT\Cookie\CookieHandler::class));
	}

	public function testThemeSelectionStartsFromTheSharedBrightMarkup():void {
		$view = new View("login/index");
		self::assertSame("bright", $view->document->documentElement->getAttribute("data-flair-theme"));
	}
}
