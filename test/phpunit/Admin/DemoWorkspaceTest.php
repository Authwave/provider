<?php
namespace Authwave\Test\Admin;

use Authwave\Admin\DemoWorkspace;
use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\Session\LoginSession;
use GT\Input\Input;
use Gt\Session\Session;
use Gt\Session\SessionStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class DemoWorkspaceTest extends TestCase {
	#[\PHPUnit\Framework\Attributes\DataProvider("hosts")]
	public function testSeedsWorkspaceFromSessionDeployment(?string $host, string $expected):void {
		// Older serialised sessions have no providerHost property. Recreate that
		// shape without running the current constructor, then restore it.
		$reflection = new ReflectionClass(ApplicationDeployment::class);
		$deployment = $reflection->newInstanceWithoutConstructor();
		$properties = [
			"id" => "deployment", "application" => new Application("app", "Example", "sender@example.test"),
			"title" => "Production", "secret" => "secret", "clientHost" => "https://example.test", "clientLoginPath" => "/login/",
		];
		if($host !== null) $properties["providerHost"] = $host;
		foreach($properties as $name => $value) $reflection->getProperty($name)->setValue($deployment, $value);
		$deployment = unserialize(serialize($deployment));
		$login = $this->createStub(LoginSession::class);
		$login->method("getDeployment")->willReturn($deployment);
		$session = $this->createStub(Session::class);
		$session->method("getStore")->willReturn(new SessionStore("demo", $session));
		$workspace = new DemoWorkspace($session, $login, $this->createStub(Input::class));
		self::assertSame($expected, $workspace->applications()["app"]["deployments"]["production"]["host"]);
	}

	public static function hosts():array {
		return [[null, "login.example.test"], ["", "login.example.test"], ["login.actual.test", "login.actual.test"]];
	}
}
