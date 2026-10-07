<?php
namespace Authwave\Test;

use Authwave\Admin\DemoWorkspace;
use Authwave\Model\{Application, ApplicationDeployment};
use Authwave\ServiceLoader;
use Authwave\Session\LoginSession;
use Gt\Config\Config;
use Gt\Input\Input;
use Gt\ServiceContainer\{Container, Injector};
use Gt\Session\{Session, SessionStore};
use PHPUnit\Framework\TestCase;

class ServiceLoaderTest extends TestCase {
	public function testAdminWorkspaceCanBeInjectedThroughRegisteredServices():void {
		$container = new Container();
		$container->addLoaderClass(new ServiceLoader(new Config(), $container));
		$session = $this->createMock(Session::class);
		$store = new SessionStore("demo", $session);
		$session->expects(self::once())->method("getStore")
			->with("AUTHWAVE_ADMIN_DEMO", true)->willReturn($store);
		$login = $this->createMock(LoginSession::class);
		$login->method("getDeployment")->willReturn(new ApplicationDeployment(
			"deployment", new Application("example-app", "Example", "sender@example.test"),
			"Production", "demo-key", "client.example.test", "/login/",
		));
		$container->setLoader(LoginSession::class, fn():LoginSession => $login);
		$container->set($session, new Input([
			"organisation" => "northstar", "application" => "portal",
		]));

		$workspace = (new Injector($container))->invoke(null,
			fn(DemoWorkspace $workspace):DemoWorkspace => $workspace,
		);

		self::assertInstanceOf(DemoWorkspace::class, $workspace);
		self::assertSame("northstar", $workspace->organisationId);
		self::assertSame("portal", $workspace->applicationId);
		self::assertSame($workspace, $container->get(DemoWorkspace::class));
	}
}
