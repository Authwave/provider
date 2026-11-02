<?php
namespace Authwave\Test\Admin;

use Authwave\Model\{Application, ApplicationDeployment};
use Authwave\Security\AdminAccess;
use Authwave\Session\LoginSession;
use Authwave\User\{LoginState, User, UserRepository};
use Gt\Config\Config;
use Gt\Http\{Request, Response, Uri};
use Gt\Session\Session;
use PHPUnit\Framework\TestCase;

class LogoutTest extends TestCase {
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider handlers
	 */
	public function testSidebarPostEndsAdminSession(string $file):void {
		chdir(dirname(__DIR__, 3));
		(new \GT\WebEngine\Logic\LogicStreamHandler())->setup();
		$deployment = new ApplicationDeployment("deployment", new Application("app", "Example", "sender@example.test"), "Example", "secret", "example.test", "/login/");
		$user = new User("admin", $deployment, "admin@example.test");
		$request = $this->createMock(Request::class);
		$request->method("getMethod")->willReturn("POST");
		$request->method("getUri")->willReturn(new Uri("https://login.example.test/admin/logout/"));
		$login = $this->createMock(LoginSession::class);
		$login->method("getState")->willReturn(LoginState::LOGGED_IN);
		$login->method("getDeployment")->willReturn($deployment);
		$login->method("getEmail")->willReturn($user->email);
		$login->expects(self::once())->method("clearDataForLogout")->with($deployment);
		$users = $this->createMock(UserRepository::class);
		$users->method("get")->willReturn($user);
		$access = $this->createMock(AdminAccess::class);
		$access->method("allows")->with($user)->willReturn(true);
		$session = $this->createMock(Session::class);
		$session->expects(self::once())->method("kill");
		$response = $this->createMock(Response::class);
		$response->expects(self::once())->method("redirect")->with("/logged-out/");
		$container = new \Gt\ServiceContainer\Container();
		$container->set($request, $response, $session, new Config(), $login, $users, $access);
		$executor = new \GT\WebEngine\Logic\LogicExecutor("Authwave", new \Gt\ServiceContainer\Injector($container));
		$assembly = new \GT\Routing\Assembly();
		$assembly->add($file);
		$invoked = iterator_to_array($executor->invoke($assembly, "do_logout"));
		self::assertCount(1, $invoked, "The owning logic assembly must implement the logout action");
	}
	public function handlers():array {
		return [["page/_component/admin-sidebar.php"], ["page/admin/logout.php"]];
	}
}
