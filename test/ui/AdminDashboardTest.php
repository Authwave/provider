<?php
namespace Authwave\Test\UI;
require_once __DIR__ . "/View.php";

use Authwave\Model\{Application, ApplicationDeployment, ApplicationTheme};
use Authwave\Security\{Audit, AnonUser};
use Authwave\Session\LoginSession;
use Authwave\Admin\DemoWorkspace;
use Gt\Input\Input;
use Gt\Session\{Session, SessionStore};
use PHPUnit\Framework\TestCase;

class AdminDashboardTest extends TestCase {
	private function render(array $query = []):View {
		chdir(dirname(__DIR__, 2));
		$query += ["period" => "30d", "application" => "ui-test-no-logo"];
		$session = $this->createMock(Session::class);
		$store = new SessionStore("demo", $session);
		$session->method("getStore")->willReturn($store);
		$login = new LoginSession(new SessionStore("admin-ui", $this->createMock(Session::class)), $this->createMock(Audit::class), $this->createMock(AnonUser::class));
		$login->setDeploymentForLogin(new ApplicationDeployment("deployment", new Application("ui-test-no-logo", "Example", "sender@example.test", themes: [new ApplicationTheme("light", ["primary" => "#123456"])]), "Example", "secret", "client.example.test", "/login/"));
		$login->setEmail("admin@example.test");
		$view = new View("admin/index");
		$input = new Input($query);
		$workspace = new DemoWorkspace($session, $login, $input);
		$view->renderAdmin($login, $input, $workspace);
		return $view;
	}

	public function testDashboardHasBrandedMenuNativeControlsAndServerChartData():void {
		$view = $this->render();
		self::assertNull($view->document->querySelector("#application-theme"));
		self::assertNull($view->document->querySelector("admin-sidebar img"));
		self::assertNotNull($view->document->querySelector("admin-sidebar > .sidebar-header application-switcher"));
		self::assertSame("Example", $view->document->querySelector("application-switcher option[selected]")->textContent);
		self::assertSame(\Authwave\Admin\DemoWorkspace::selectionValue("example", "ui-test-no-logo"), $view->document->querySelector("application-switcher option[selected]")->value);
		self::assertNull($view->document->querySelector(".dashboard-header .identity"));
		self::assertSame("Go to Example", $view->document->querySelector(".dashboard-header > a")->textContent);
		self::assertSame("https://client.example.test/", $view->document->querySelector(".dashboard-header > a")->href);
		self::assertFalse($view->document->querySelector(".sidebar-menu")->hasAttribute("open"));
		self::assertSame("page", $view->document->querySelector(".side-navigation a")->getAttribute("aria-current"));
		self::assertCount(7, $view->document->querySelectorAll(".activity-table tbody tr"));
		$payload = json_decode($view->document->querySelector("admin-chart .chart")->getAttribute("data-chart"), true, flags: JSON_THROW_ON_ERROR);
		self::assertCount(12, $payload["current"]);
		self::assertCount(12, $view->document->querySelectorAll("admin-chart-data .chart-data tbody tr"));
		self::assertFalse($view->document->querySelector('a[data-icon="chevron-right"]')->hasAttribute("hidden"));
		self::assertTrue($view->document->querySelector('a[data-icon="chevron-left"]')->hasAttribute("hidden"));
		foreach($view->document->querySelectorAll(".report-chart header form") as $form) {
			self::assertSame("get", $form->method);
			self::assertFalse($form->hasAttribute("data-flux"));
		}
	}

	public function testFiltersAndPaginationAreAppliedBeforeRendering():void {
		$view = $this->render(["activity" => "abandoned", "page" => "2"]);
		self::assertCount(1, $view->document->querySelectorAll(".activity-table tbody tr"));
		self::assertSame("Abandoned", $view->document->querySelector(".activity-table .status")->textContent);
		self::assertTrue($view->document->querySelector('admin-activity-filters input[type="checkbox"][name="activityAbandoned"]')->hasAttribute("checked"));
		self::assertStringContainsString("activity=abandoned", $view->document->querySelector('a[data-icon="chevron-left"]')->href);
		$filtered = $this->render(["search" => "sienna", "status" => "success", "method" => "email"]);
		self::assertCount(2, $filtered->document->querySelectorAll(".activity-table tbody tr"));
		self::assertSame("success", $filtered->document->querySelector('input[type="hidden"][name="status"][value="success"]')->value);
		$empty = $this->render(["search" => "unknown-user"]);
		self::assertCount(0, $empty->document->querySelectorAll(".activity-table tbody tr"));
		self::assertFalse($empty->document->querySelector('.activity-panel > p')->hasAttribute("hidden"));
	}

	public function testInvalidInputsFallBackAndDatesAreNormalised():void {
		$view = $this->render(["period" => "invalid", "status" => "invalid", "from" => "2026-10-05", "to" => "2026-10-01", "page" => "-99"]);
		self::assertCount(0, $view->document->querySelectorAll('button[name="period"][aria-pressed="true"]'));
		self::assertSame("true", $view->document->querySelector("admin-date-range summary")->getAttribute("aria-current"));
		self::assertSame("2026-10-01", $view->document->querySelector('input[type="date"][name="from"]')->value);
		self::assertSame("2026-10-05", $view->document->querySelector('input[type="date"][name="to"]')->value);
		self::assertSame("Overview", $view->document->querySelector("#overview-title")->textContent);
		self::assertSame("An overview of activity between 1 Oct 2026 and 5 Oct 2026", $view->document->querySelector(".report-chart header p")->textContent);
	}

	public function testComparisonControlsAndChartUseTheSelectedPeriod():void {
		foreach(["previous" => "Last period", "previous-1" => "Last period - 1", "month" => "Same point last month", "quarter" => "Previous quarter", "year" => "Previous year"] as $value => $label) {
			$view = $this->render(["reportComparison" => $value]);
			self::assertNull($view->document->querySelector(".dashboard-controls"));
			self::assertSame($value, $view->document->querySelector("admin-comparison option[selected]")->value);
			$payload = json_decode($view->document->querySelector("admin-chart .chart")->getAttribute("data-chart"), true, flags: JSON_THROW_ON_ERROR);
			self::assertSame($label, $payload["comparisonTitle"]);
			self::assertSame($value, $view->document->querySelector('.segmented-control form input[name="reportComparison"]')->value);
		}
	}

	public function testActivityCheckboxesCombineStatusesAndAllowAnEmptySelection():void {
		$view = $this->render(["activitySuccess" => "no", "activityFailed" => "yes", "activityAbandoned" => "yes"]);
		foreach($view->document->querySelectorAll(".activity-table tbody .status") as $status) {
			self::assertContains($status->getAttribute("data-status"), ["failed", "abandoned"]);
		}
		self::assertNull($view->document->querySelector(".activity-controls .metric"));
		self::assertFalse($view->document->querySelector('admin-activity-filters input[type="checkbox"][name="activitySuccess"]')->hasAttribute("checked"));
		self::assertStringContainsString("activitySuccess=no", $view->document->querySelector('a[data-icon="chevron-right"]')->href);
		$failed = $this->render(["activitySuccess" => "no", "activityFailed" => "yes", "activityAbandoned" => "no"]);
		self::assertCount(4, $failed->document->querySelectorAll(".activity-table tbody tr"));
		$empty = $this->render(["activitySuccess" => "no", "activityFailed" => "no", "activityAbandoned" => "no"]);
		self::assertCount(0, $empty->document->querySelectorAll(".activity-table tbody tr"));
		self::assertFalse($empty->document->querySelector('.activity-panel > p')->hasAttribute("hidden"));
	}
}
