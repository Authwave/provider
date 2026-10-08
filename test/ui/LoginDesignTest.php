<?php
namespace Authwave\Test\UI;

require_once __DIR__ . "/View.php";

use Authwave\Model\{Application, ApplicationDeployment, ApplicationTheme};
use Authwave\Security\{AnonUser, Audit};
use Authwave\Security\AdminAccess;
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

	/** @dataProvider brandingCases */
	public function testApplicationBranding(string $page, array $files, ?string $colour, string $light, string $dark, ?string $style, ?string $secondaryColour = null):void {
		$id = "ui-branding-" . bin2hex(random_bytes(8));
		$directory = "data/upload/$id";
		mkdir($directory, 0777, true);
		try {
			foreach($files as $file) {
				file_put_contents("$directory/$file", "test image");
			}
			$deployment = new ApplicationDeployment("test-deployment",
				new Application($id, "Branded app", "test@example.test", themes: [new ApplicationTheme("light", ["primary" => $colour, "secondary" => $secondaryColour])]),
				"Branded application", random_bytes(32), "client.example.test", "/callback");
			$this->login->setDeploymentForLogin($deployment);
			$view = new View("login/$page");
			$this->call("_common", "go", $view->document, $view->binder, $this->login);
			$picture = $view->document->querySelector("picture");
			$source = $picture->querySelector("source");
			self::assertSame("(prefers-color-scheme: dark)", $source->getAttribute("media"));
			self::assertSame($dark ? "/$directory/$dark" : "/asset/default-logo.svg", $source->getAttribute("srcset"));
			self::assertSame($light ? "/$directory/$light" : "/asset/default-logo.svg", $picture->querySelector("img")->getAttribute("src"));
			self::assertSame("Branded application logo", $picture->querySelector("img")->getAttribute("alt"));
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

	public function brandingCases():array {
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
				new ApplicationTheme("light", ["primary" => "#123456"]),
				new ApplicationTheme("dark", ["primary" => "#abcdef", "pageBackground" => "#111"]),
			]), "Test application", random_bytes(32), "client.example.test", "/callback"));
		$view = new View("login/index");
		$this->call("_common", "go", $view->document, $view->binder, $this->login);
		self::assertSame(":root[data-flair-theme=\"bright\"][data-color-scheme=\"light\"] { --theme-color-primary: #123456; }\n:root[data-flair-theme=\"bright\"][data-color-scheme=\"dark\"] { --theme-color-primary: #abcdef; --theme-color-page: #111; }",
			$view->document->querySelector("#application-theme")->textContent);
		$html = (string)$view->document;
		self::assertGreaterThan(strpos($html, '/style.css'), strpos($html, 'id="application-theme"'));
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
		$this->call("success", "go", new Input(["debug" => "1"]), $this->response, $view->binder, $this->login, $this->users, $this->session, $this->audit, new AdminAccess($this->createMock(\Gt\Database\Query\QueryCollection::class), null), $this->createMock(Request::class));
		$uri = $view->document->querySelector("main p a")->getAttribute("href");
		self::assertStringStartsWith("https://client.example.test/callback?", $uri);
		parse_str(parse_url($uri, PHP_URL_QUERY), $query);
		self::assertSame("account", $query["next"]);
		$data = (new EncryptedMessage($query["AUTHWAVE_RESPONSE_DATA"], $iv))->decrypt(new Key($this->deployment->secret));
		self::assertSame(["id" => "test-user", "email" => "test@example.test"], json_decode((string)$data, true));
	}
	public function testAdminSuccessOffersBothDestinationsAndRetainsSession():void {
		$view = new View("login/success");
		$this->login->setState(LoginState::LOGGED_IN);
		$this->login->setData(["secretIv" => (string)new InitVector()]);
		$this->users->method("get")->willReturn($this->user);
		$this->session->expects(self::never())->method("kill");
		$this->response->expects(self::never())->method("redirect");
		$this->call("_common", "go", $view->document, $view->binder, $this->login);
		$this->call("success", "go", new Input([]), $this->response, $view->binder, $this->login, $this->users, $this->session, $this->audit, new AdminAccess($this->createMock(\Gt\Database\Query\QueryCollection::class), $this->user->email), $this->createMock(Request::class));
		$link = $view->document->querySelector('a[href="/admin/"]');
		self::assertNotNull($link);
		self::assertFalse($link->parentElement->hasAttribute("hidden"));
		self::assertNull($view->document->querySelector("a[data-client-redirect]"));
		self::assertStringContainsString("AUTHWAVE_RESPONSE_DATA=", $view->document->querySelector("main p a")->getAttribute("href"));
	}

	public function testOrdinaryUserStillRedirectsAutomatically():void {
		$view = new View("login/success");
		$this->login->setState(LoginState::LOGGED_IN);
		$this->login->setData(["secretIv" => (string)new InitVector()]);
		$this->users->method("get")->willReturn($this->user);
		$this->session->expects(self::once())->method("kill");
		try {
			$this->call("success", "go", new Input([]), $this->response, $view->binder, $this->login, $this->users, $this->session, $this->audit, new AdminAccess($this->createMock(\Gt\Database\Query\QueryCollection::class), "someone-else@example.test"), $this->createMock(Request::class));
			self::fail("Expected client redirect");
		}
		catch(Redirect $redirect) {
			self::assertStringStartsWith("https://client.example.test/callback?AUTHWAVE_RESPONSE_DATA=", $redirect->getMessage());
		}
		self::assertTrue($view->document->querySelector('a[href="/admin/"]')->parentElement->hasAttribute("hidden"));
	}

	public function testDirectAdminVisitInitialisesDeploymentWithoutClientRedirect():void {
		$login = new LoginSession(new SessionStore("fresh", $this->session), $this->audit, $this->anonymous);
		$apps = $this->createMock(\Authwave\Model\ApplicationRepository::class);
		$apps->expects(self::once())->method("getDeploymentByProviderHost")->with("login.example.test")->willReturn($this->deployment);
		$apps->expects(self::never())->method("redirectToDeployment");
		$this->callFile("page/_common.php", "go", $apps, new \Gt\Http\Uri("https://login.example.test/admin"), $login, $this->session, $this->response);
		self::assertSame($this->deployment, $login->getDeployment());
		$view = new View("admin/index");
		$this->redirects("/login/", fn() => $this->callFile("page/admin/_common.php", "go", $login, $this->users, new AdminAccess($this->createMock(\Gt\Database\Query\QueryCollection::class), $this->user->email), $this->response, $view->binder));
		self::assertTrue($login->isAdminRequested());
	}

	public function testAdminDestinationSurvivesAuthenticationWithoutClientCipher():void {
		$this->login->requestAdmin();
		$this->login->setState(LoginState::LOGGED_IN);
		$this->users->method("get")->willReturn($this->user);
		$this->session->expects(self::never())->method("kill");
		$view = new View("login/success");
		$this->redirects("/admin/", fn() => $this->call("success", "go", new Input([]), $this->response, $view->binder, $this->login, $this->users, $this->session, $this->audit, new AdminAccess($this->createMock(\Gt\Database\Query\QueryCollection::class), $this->user->email), $this->createMock(Request::class)));
		$admin = new View("admin/index");
		$this->callFile("page/admin/_common.php", "go", $this->login, $this->users, new AdminAccess($this->createMock(\Gt\Database\Query\QueryCollection::class), $this->user->email), $this->response, $admin->binder);
		self::assertNotNull($admin->document->querySelector("admin-sidebar"));
		self::assertSame("page", $admin->document->querySelector(".side-navigation a")->getAttribute("aria-current"));
		self::assertFalse($this->login->isAdminRequested());
	}

	public function testAuthenticatedNonAdminCannotAccessDashboard():void {
		$this->login->setState(LoginState::LOGGED_IN);
		$this->users->method("get")->willReturn($this->user);
		$view = new View("admin/index");
		$this->expectException(\Authwave\Security\AdminAccessDenied::class);
		$this->callFile("page/admin/_common.php", "go", $this->login, $this->users, new AdminAccess($this->createMock(\Gt\Database\Query\QueryCollection::class), "someone-else@example.test"), $this->response, $view->binder);
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
		self::assertFalse((new AdminAccess($this->createMock(\Gt\Database\Query\QueryCollection::class), null))->allows($this->user));
		self::assertFalse((new AdminAccess($this->createMock(\Gt\Database\Query\QueryCollection::class), ""))->allows($this->user));
		self::assertFalse((new AdminAccess($this->createMock(\Gt\Database\Query\QueryCollection::class), $this->user->email))->allows(null));
		self::assertTrue((new AdminAccess($this->createMock(\Gt\Database\Query\QueryCollection::class), strtoupper($this->user->email)))->allows($this->user));
	}

	public function testAccessDeniedPageUsesLoginBrandingAndStartsClientHandshake():void {
		$this->login->setState(LoginState::LOGGED_IN);
		$this->login->requestAdmin();
		$view = new View("_error/403");
		$this->callFile("page/_error/_common.php", "go", $view->document, $view->binder, $this->login);
		self::assertTrue($view->document->body->classList->contains("dir--login"));
		self::assertSame("Test application logo", $view->document->querySelector(".logo")->getAttribute("alt"));
		self::assertStringContainsString("doesn't have access to user administration", $view->document->body->textContent);
		$link = $view->document->querySelector(".auth-heading a");
		self::assertSame("https://client.example.test/callback", $link->getAttribute("href"));
		self::assertSame("Continue to Test application", $link->textContent);
		self::assertSame("/login/access-denied/", $view->document->querySelector("form")->getAttribute("action"));
		self::assertFalse($this->login->isAdminRequested());
	}

	public function testSwitchAccountEndpointRequiresAuthentication():void {
		$this->redirects("/login/", fn() => $this->call("access-denied", "go", $this->login, $this->response));
		self::assertTrue($this->login->isAdminRequested());
	}

	public function testSwitchingAccountClearsIdentityAndRemembersAdminDestination():void {
		$this->login->setState(LoginState::LOGGED_IN);
		$request = $this->createMock(Request::class);
		$request->method("getMethod")->willReturn("POST");
		$request->method("getUri")->willReturn(new \Gt\Http\Uri("https://login.example.test/login/access-denied/"));
		$this->redirects("/login/", fn() => $this->call("access-denied", "do_switch_account", $request, $this->login, $this->response));
		self::assertNull($this->login->getEmail());
		self::assertSame(LoginState::NOT_LOGGED_IN, $this->login->getState());
		self::assertTrue($this->login->isAdminRequested());
	}

	public function testSwitchingAccountCannotRunFromGetOrAdminErrorRendering():void {
		$this->login->setState(LoginState::LOGGED_IN);
		$this->response->expects(self::never())->method("redirect");
		foreach([["GET", "/login/access-denied/"], ["POST", "/admin/"]] as [$method, $path]) {
			$request = $this->createMock(Request::class);
			$request->method("getMethod")->willReturn($method);
			$request->method("getUri")->willReturn(new \Gt\Http\Uri("https://login.example.test$path"));
			$this->call("access-denied", "do_switch_account", $request, $this->login, $this->response);
			self::assertSame($this->user->email, $this->login->getEmail());
			self::assertSame(LoginState::LOGGED_IN, $this->login->getState());
		}
	}

	public function testFluxSuccessRendersBrowserHandoffWithoutCrossOriginFetch():void {
		$view = new View("login/success");
		$this->login->setState(LoginState::LOGGED_IN);
		$this->login->setData(["secretIv" => (string)new InitVector()]);
		$this->users->method("get")->willReturn($this->user);
		$this->session->expects(self::once())->method("kill");
		$this->response->expects(self::never())->method("redirect");
		$request = $this->createMock(Request::class);
		$request->method("getHeaderLine")->with("X-Authwave-Flux")->willReturn("1");
		$this->call("success", "go", new Input([]), $this->response, $view->binder, $this->login, $this->users, $this->session, $this->audit, new AdminAccess($this->createMock(\Gt\Database\Query\QueryCollection::class)), $request);
		$link = $view->document->querySelector("a[data-client-redirect]");
		self::assertNotNull($link);
		self::assertStringStartsWith("https://client.example.test/callback?AUTHWAVE_RESPONSE_DATA=", $link->getAttribute("href"));
	}

	public function testAdminLogoutClearsSessionAndUsesConfiguredDestination():void {
		$this->login->setState(LoginState::LOGGED_IN);
		$this->users->method("get")->willReturn($this->user);
		$this->session->expects(self::once())->method("kill");
		$request = $this->createMock(Request::class);
		$request->method("getMethod")->willReturn("POST");
		$request->method("getUri")->willReturn(new \Gt\Http\Uri("https://login.example.test/admin/logout/"));
		$config = $this->createMock(\Gt\Config\Config::class);
		$config->method("getString")->with("authwave.logout_redirect")->willReturn("https://public.example.test/");
		$this->redirects("https://public.example.test/", fn() => $this->callFile("page/admin/logout.php", "do_logout", $request, $this->response, $this->session, $config, $this->login, $this->users, new AdminAccess($this->createMock(\Gt\Database\Query\QueryCollection::class), $this->user->email)));
		self::assertNull($this->login->getEmail());
		self::assertSame(LoginState::NOT_LOGGED_IN, $this->login->getState());
	}

	public function testAdminLogoutNeverRunsFromGetOrAnotherRoute():void {
		$this->login->setState(LoginState::LOGGED_IN);
		$this->session->expects(self::never())->method("kill");
		$this->response->expects(self::never())->method("redirect");
		foreach([["GET", "/admin/logout/"], ["POST", "/admin/security/"]] as [$method, $path]) {
			$request = $this->createMock(Request::class);
			$request->method("getMethod")->willReturn($method);
			$request->method("getUri")->willReturn(new \Gt\Http\Uri("https://login.example.test$path"));
			$this->callFile("page/admin/logout.php", "do_logout", $request, $this->response, $this->session, new \Gt\Config\Config(), $this->login, $this->users, new AdminAccess($this->createMock(\Gt\Database\Query\QueryCollection::class), $this->user->email));
			self::assertSame(LoginState::LOGGED_IN, $this->login->getState());
		}
	}

	public function demoPostHandlers():array {
		return [["page/admin/_common.php"]];
	}

	/** @dataProvider demoPostHandlers */
	public function testDemoPostCannotMutateBeforeAdminAccessIsChecked(string $handler):void {
		$this->login->setState(LoginState::LOGGED_IN);
		$this->users->method("get")->willReturn($this->user);
		$store = new SessionStore("demo", $this->session);
		$this->session->method("getStore")->willReturn($store);
		$input = new Input(["operation" => "sessions", "sessionTimeout" => "5", "idleTimeout" => "5"]);
		$workspace = new \Authwave\Admin\DemoWorkspace($this->session, $this->login, $input);
		$request = $this->createMock(Request::class);
		$request->method("getMethod")->willReturn("POST");
		$request->method("getUri")->willReturn(new \Gt\Http\Uri("https://login.example.test/admin/security/"));
		try {
			$this->callFile($handler, "do_demo", $request, $input, $this->login, $this->users, new AdminAccess($this->createMock(\Gt\Database\Query\QueryCollection::class), "someone-else@example.test"), $workspace, $this->response);
			self::fail("Expected administrator access to be checked before mutation.");
		}
		catch(\Authwave\Security\AdminAccessDenied) {
			self::assertSame("1440", $workspace->settings()["sessionTimeout"]);
		}
	}

	public function testFreshAdminSessionEstablishesDeploymentBeforeComponentBindings():void {
		$login = new LoginSession(new SessionStore("fresh-admin", $this->session), $this->audit, $this->anonymous);
		$applications = $this->createMock(\Authwave\Model\ApplicationRepository::class);
		$applications->expects(self::once())->method("getDeploymentByProviderHost")
			->with("localhost:8080")->willReturn($this->deployment);
		try {
			$this->callFile("page/_component/admin-sidebar.php", "go_before", $login, $applications,
				new \Gt\Http\Uri("http://localhost:8080/admin/"), $this->response);
			self::fail("Expected authentication before component bindings.");
		}
		catch(Redirect $redirect) {
			self::assertSame("/login/", $redirect->getMessage());
			self::assertSame($this->deployment, $login->getDeployment());
			self::assertTrue($login->isAdminRequested());
		}
	}

	public function testLoggedOutLandingDoesNotReinitialiseOrRedirectToClient():void {
		$apps = $this->createMock(\Authwave\Model\ApplicationRepository::class);
		$apps->expects(self::never())->method("getDeploymentByProviderHost");
		$this->response->expects(self::never())->method("redirect");
		$this->callFile("page/_common.php", "go", $apps, new \Gt\Http\Uri("https://login.example.test/logged-out/"), new LoginSession(new SessionStore("empty", $this->session), $this->audit, $this->anonymous), $this->session, $this->response);
	}


	public function testMonochromePreferenceIsRenderedWithoutChangingLoginState():void {
		$request = $this->createMock(\Gt\Http\ServerRequest::class);
		$request->method("getQueryParams")->willReturn([]);
		$request->method("getCookieParams")->willReturn(["authwave-flair-theme" => "base"]);
		$view = new View("login/index");
		$this->callFile("page/_common.php", "go_after", $view->document, $request);
		self::assertSame("base", $view->document->documentElement->getAttribute("data-flair-theme"));
	}

	public function testUnknownThemePreferenceFallsBackToBright():void {
		$request = $this->createMock(\Gt\Http\ServerRequest::class);
		$request->method("getQueryParams")->willReturn([]);
		$request->method("getCookieParams")->willReturn(["authwave-flair-theme" => "unknown"]);
		$view = new View("login/index");
		$this->callFile("page/_common.php", "go_after", $view->document, $request);
		self::assertSame("bright", $view->document->documentElement->getAttribute("data-flair-theme"));
	}
}
