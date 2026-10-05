<?php
namespace Authwave\Test\UI;
require_once __DIR__ . "/View.php";

use Authwave\Model\{Application, ApplicationDeployment, ApplicationTheme};
use Authwave\Security\{Audit, AnonUser};
use Authwave\Session\LoginSession;
use Authwave\View\AdminDashboard;
use Gt\Input\Input;
use Gt\Session\{Session, SessionStore};
use PHPUnit\Framework\TestCase;

class AdminDashboardTest extends TestCase {
	private function render(array $query = []):View {
		chdir(dirname(__DIR__, 2));
		$login = new LoginSession(new SessionStore("admin-ui", $this->createMock(Session::class)), $this->createMock(Audit::class), $this->createMock(AnonUser::class));
		$login->setDeploymentForLogin(new ApplicationDeployment("deployment", new Application("ui-test-no-logo", "Example", "sender@example.test", themes: [new ApplicationTheme("light", ["primary" => "#123456"])]), "Example", "secret", "client.example.test", "/login/"));
		$login->setEmail("admin@example.test");
		$view = new View("admin/index");
		(new AdminDashboard())->apply($view->document, $view->binder, $login, new Input($query));
		return $view;
	}

	public function testDashboardHasBrandedMenuNativeControlsAndServerChartData():void {
		$view = $this->render();
		self::assertNull($view->document->querySelector("#application-theme"));
		self::assertSame("Example", $view->document->querySelector("admin-sidebar img")->alt);
		self::assertSame("/asset/default-logo.svg", $view->document->querySelector("admin-sidebar img")->src);
		self::assertSame("Example", $view->document->querySelector("application-switcher option[selected]")->textContent);
		self::assertSame("ui-test-no-logo", $view->document->querySelector("application-switcher option[selected]")->value);
		self::assertNull($view->document->querySelector(".dashboard-header .identity"));
		self::assertSame("Go to Example", $view->document->querySelector(".dashboard-header > a")->textContent);
		self::assertSame("https://client.example.test/", $view->document->querySelector(".dashboard-header > a")->href);
		self::assertFalse($view->document->querySelector(".sidebar-menu")->hasAttribute("open"));
		self::assertSame("page", $view->document->querySelector(".side-navigation a")->getAttribute("aria-current"));
		self::assertCount(7, $view->document->querySelectorAll(".activity-table tbody tr"));
		$payload = json_decode($view->document->querySelector("admin-chart script")->textContent, true, flags: JSON_THROW_ON_ERROR);
		self::assertCount(12, $payload["current"]);
		self::assertCount(12, $view->document->querySelectorAll(".chart-data tbody tr"));
		self::assertFalse($view->document->querySelector('a[data-icon="chevron-right"]')->hasAttribute("hidden"));
		self::assertTrue($view->document->querySelector('a[data-icon="chevron-left"]')->hasAttribute("hidden"));
		foreach($view->document->querySelectorAll("main form") as $form) {
			self::assertSame("get", $form->method);
			self::assertFalse($form->hasAttribute("data-flux"));
		}
	}

	public function testFiltersAndPaginationAreAppliedBeforeRendering():void {
		$view = $this->render(["activity" => "abandoned", "page" => "2"]);
		self::assertCount(1, $view->document->querySelectorAll(".activity-table tbody tr"));
		self::assertSame("Abandoned", $view->document->querySelector(".activity-table .status")->textContent);
		self::assertSame("true", $view->document->querySelector('button[value="abandoned"]')->getAttribute("aria-pressed"));
		self::assertStringContainsString("activity=abandoned", $view->document->querySelector('a[data-icon="chevron-left"]')->href);
		$filtered = $this->render(["search" => "sienna", "status" => "success", "method" => "email"]);
		self::assertCount(2, $filtered->document->querySelectorAll(".activity-table tbody tr"));
		self::assertSame("success", $filtered->document->querySelector('select[name="status"] option[selected]')->value);
		$empty = $this->render(["search" => "unknown-user"]);
		self::assertCount(0, $empty->document->querySelectorAll(".activity-table tbody tr"));
		self::assertFalse($empty->document->querySelector('.activity-panel > p')->hasAttribute("hidden"));
	}

	public function testInvalidInputsFallBackAndDatesAreNormalised():void {
		$view = $this->render(["period" => "invalid", "status" => "invalid", "from" => "2026-10-05", "to" => "2026-10-01", "page" => "-99"]);
		self::assertSame("true", $view->document->querySelector('button[value="12m"]')->getAttribute("aria-pressed"));
		self::assertSame("2026-10-01", $view->document->querySelector('input[type="date"][name="from"]')->value);
		self::assertSame("2026-10-05", $view->document->querySelector('input[type="date"][name="to"]')->value);
	}
}
