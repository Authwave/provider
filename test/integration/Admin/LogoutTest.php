<?php

namespace Authwave\Test\Integration\Admin;

use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\Security\AdminAccess;
use Authwave\Session\LoginSession;
use Authwave\User\LoginState;
use Authwave\Model\User;
use Authwave\User\UserRepository;
use GT\Config\Config;
use GT\Http\Request;
use GT\Http\Response;
use GT\Http\Uri;
use GT\Session\Session;
use PHPUnit\Framework\TestCase;

class LogoutTest extends TestCase {
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
	#[\PHPUnit\Framework\Attributes\DataProvider("handlers")]
	public function testSidebarPostEndsAdminSession(string $file):void {
		chdir(dirname(__DIR__, 3));
		(new \GT\WebEngine\Logic\LogicStreamHandler())->setup();
		$deployment = new ApplicationDeployment("deployment", new Application("app", "Example", "sender@example.test"), "Example", "secret", "example.test", "/login/");
		$user = new User("admin", $deployment, "admin@example.test");
		$request = $this->createStub(Request::class);
		$request->method("getMethod")->willReturn("POST");
		$request->method("getUri")->willReturn(new Uri("https://login.example.test/admin/logout/"));
		$login = $this->createMock(LoginSession::class);
		$login->method("getState")->willReturn(LoginState::LOGGED_IN);
		$login->method("getDeployment")->willReturn($deployment);
		$login->method("getEmail")->willReturn($user->email);
		$login->expects(self::once())->method("clearDataForLogout")->with($deployment);
		$users = $this->createStub(UserRepository::class);
		$users->method("get")->willReturn($user);
		$access = $this->createMock(AdminAccess::class);
		$access->expects(self::atLeastOnce())->method("allows")->with($user)->willReturn(true);
		$session = $this->createMock(Session::class);
		$session->expects(self::once())->method("kill");
		$response = $this->createMock(Response::class);
		$response->expects(self::once())->method("redirect")->with("/logged-out/");
		$container = new \GT\ServiceContainer\Container();
		$area = new \Authwave\Admin\Onboarding\AdminArea($login, $users, $access,
			$this->createStub(\Authwave\Model\ApplicationRepository::class));
		$container->set($request, $response, $session, new Config(), $area, $this->createStub(\GT\Cookie\CookieHandler::class));
		$executor = new \GT\WebEngine\Logic\LogicExecutor("Authwave", new \GT\ServiceContainer\Injector($container));
		$assembly = new \GT\Routing\Assembly();
		$assembly->add($file);
		$invoked = iterator_to_array($executor->invoke($assembly, "do_logout"));
		self::assertCount(1, $invoked, "The owning logic assembly must implement the logout action");
	}

	public static function handlers():array {
		return [["page/_component/admin-sidebar.php"], ["page/admin/logout.php"]];
	}
}
