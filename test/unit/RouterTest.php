<?php
namespace Authwave\Test;

require_once dirname(__DIR__, 2) . "/vendor/phpgt/webengine/router.default.php";

use GT\WebEngine\DefaultRouter;
use Gt\Http\Request;
use Gt\Http\Uri;
use Gt\Routing\Path\DynamicPath;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase {
	/** @dataProvider routes */
	public function testPageRouting(string $path, ?int $status, string $page):void {
		chdir(dirname(__DIR__, 2));
		$request = $this->createMock(Request::class);
		$request->method("getUri")->willReturn(new Uri("https://login.example.test$path"));
		$request->method("getMethod")->willReturn("GET");
		$request->method("getHeaderLine")->willReturn("text/html");
		$container = new \Gt\ServiceContainer\Container();
		$container->set($request);
		$router = new DefaultRouter(errorStatus: $status);
		$router->setContainer($container);
		$router->route($request);
		$views = iterator_to_array($router->getViewAssembly());
		$logic = iterator_to_array($router->getLogicAssembly());
		self::assertContains("page/$page.html", $views);
		self::assertContains("page/_header.html", $views);
		self::assertContains("page/_footer.html", $views);
		if($status === 403) {
			self::assertContains("page/_error/_common.php", $logic);
			self::assertNotContains("page/login/access-denied.php", $logic);
			self::assertNotContains("page/admin/_common.php", $logic);
			$dynamicPath = new DynamicPath($path, $router->getViewAssembly());
			self::assertSame("/_error/403", $dynamicPath->getUrl("page/"));
		}
	}

	public function routes():array {
		return [
			["/admin", 403, "_error/403"],
			["/admin/", 403, "_error/403"],
			["/admin/users/", 403, "_error/403"],
			["/admin/", null, "admin/index"],
			["/login/", null, "login/index"],
		];
	}
}
