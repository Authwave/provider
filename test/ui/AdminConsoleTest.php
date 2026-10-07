<?php
namespace Authwave\Test\UI;
require_once __DIR__ . "/View.php";

use Authwave\Admin\DemoWorkspace;
use Authwave\Model\{Application, ApplicationDeployment};
use Authwave\Security\{AnonUser, Audit};
use Authwave\Session\LoginSession;
use Gt\Input\Input;
use Gt\Session\{Session, SessionStore};
use PHPUnit\Framework\TestCase;

class AdminConsoleTest extends TestCase {
	private Session $session;
	private LoginSession $login;

	protected function setUp():void {
		chdir(dirname(__DIR__, 2));
		$this->session = $this->createMock(Session::class);
		$store = new SessionStore("demo", $this->session);
		$this->session->method("getStore")->willReturn($store);
		$this->login = new LoginSession(new SessionStore("login", $this->session), $this->createMock(Audit::class), $this->createMock(AnonUser::class));
		$this->login->setDeploymentForLogin(new ApplicationDeployment("deployment", new Application("console-test", "Example", "sender@example.test"), "Example production", "real-secret-must-not-be-rendered", "client.example.test", "/login/"));
		$this->login->setEmail("admin@example.test");
	}
	private function workspace(array $query = []):DemoWorkspace { return new DemoWorkspace($this->session, $this->login, new Input($query)); }
	private function render(string $page, array $query = []):View {
		$view = new View("admin/$page");
		$input = new Input($query);
		$workspace = $this->workspace($query);
		$view->renderAdmin($this->login, $input, $workspace);
		return $view;
	}

	public function testEverySectionRendersWithScopeAndNativeForms():void {
		foreach(["index", "organisation", "security", "emails", "applications", "customisation", "users", "integrations", "billing", "logout"] as $page) {
			$view = $this->render($page);
			if($page === "index") {
				self::assertNull($view->document->querySelector("main admin-page-heading"));
			}
			else self::assertNotNull($view->document->querySelector("main h1"), $page);
			self::assertSame("Example", $view->document->querySelector("application-switcher option[selected]")->textContent);
			self::assertNull($view->document->querySelector("#application-theme"));
			self::assertStringNotContainsString("real-secret-must-not-be-rendered", $view->document->body->textContent);
			if($page !== "organisation") self::assertSame($page === "index" ? "dashboard" : $page, $view->document->querySelector('[data-nav][aria-current="page"]')->getAttribute("data-nav"));
			foreach($view->document->querySelectorAll("form:not([data-scope-form])") as $form) {
				self::assertNotNull($form->querySelector('[name="organisation"]'), $page);
				self::assertNotNull($form->querySelector('[name="application"]'), $page);
				self::assertFalse($form->hasAttribute("data-flux"));
			}
		}
	}
	public function testScopeLimitsUsersAndCensorsEmailsUnlessRequested():void {
		$all = $this->render("users");
		self::assertCount(1, $this->workspace()->applications());
		foreach($all->document->querySelectorAll("application-switcher option") as $option) self::assertNotSame("All applications", $option->textContent);
		self::assertCount(1, $this->workspace(["application" => "all"])->applications());
		self::assertStringNotContainsString("sienna@example.test", $all->document->querySelector("main")->textContent);
		self::assertSame("Page 1 of 1 · 7 users", $all->document->querySelector('[data-bound] + nav[aria-label="User pages"]')->previousElementSibling->textContent);
		$scoped = $this->render("users", ["application" => "operations", "search" => "sienna", "reveal" => "yes"]);
		self::assertCount(1, $scoped->document->querySelectorAll(".record-table tbody tr"));
		self::assertStringContainsString("sienna@example.test", $scoped->document->querySelector(".record-table")->textContent);
		self::assertStringContainsString("application=operations", $scoped->document->querySelector('[data-nav="security"]')->href);
		$other = $this->render("users", ["organisation" => "northstar", "application" => "operations"]);
		self::assertSame("Customer portal", $other->document->querySelector("application-switcher option[selected]")->textContent);
		self::assertCount(3, $other->document->querySelectorAll("application-switcher option:not([disabled])"));
	}
	public function testApplicationSwitcherKeepsPageDeploymentContextSeparate():void {
		$value = DemoWorkspace::selectionValue("example", "console-test", "staging");
		$workspace = $this->workspace(["scope" => $value]);
		self::assertSame("staging", $workspace->deploymentId);
		self::assertSame(["staging"], array_keys($workspace->applications()["console-test"]["deployments"]));
		$view = $this->render("applications", ["scope" => $value]);
		self::assertCount(1, $view->document->querySelectorAll(".scope-switcher select"));
		self::assertNull($view->document->querySelector("organisation-switcher"));
		self::assertNull($view->document->querySelector("application-switcher optgroup"));
		$selected = $view->document->querySelector("application-switcher option[selected]");
		self::assertSame("Example", $selected->textContent);
		self::assertSame(DemoWorkspace::selectionValue("example", "console-test"), $selected->value);
		self::assertStringContainsString("deploymentScope=staging", $view->document->querySelector('[data-nav="users"]')->href);
		self::assertSame("staging", $this->workspace()->deploymentId);
		self::assertSame("all", $this->workspace(["scope" => DemoWorkspace::selectionValue("example", "console-test")])->deploymentId);
		$other = $this->workspace(["scope" => DemoWorkspace::selectionValue("northstar", "portal", "production")]);
		self::assertSame("northstar", $other->organisationId);
		self::assertSame("portal", $other->applicationId);
		self::assertSame("production", $other->deploymentId);
		self::assertSame("all", $this->workspace(["scope" => DemoWorkspace::selectionValue("northstar", "portal", "unknown")])->deploymentId);
	}

	public function testSettingsPersistAndRemainSeparateBetweenApplications():void {
		$workspace = $this->workspace();
		$workspace->mutate(new Input(["operation" => "sessions", "sessionTimeout" => "360", "idleTimeout" => "15"]));
		self::assertSame("1440", $this->workspace(["application" => "operations"])->settings()["sessionTimeout"]);
		$this->workspace(["application" => "operations"])->mutate(new Input(["operation" => "sessions", "sessionTimeout" => "120", "idleTimeout" => "10"]));
		self::assertSame("120", $this->workspace(["application" => "operations"])->settings()["sessionTimeout"]);
		self::assertSame("360", $this->workspace(["application" => "all"])->settings()["sessionTimeout"]);
		self::assertSame("1440", $this->workspace(["organisation" => "northstar"])->settings()["sessionTimeout"]);
	}
	public function testCreateOrganisationApplicationSenderAndUser():void {
		$workspace = $this->workspace();
		$workspace->mutate(new Input(["operation" => "create-organisation", "name" => "New team"]));
		self::assertCount(0, $workspace->applications());
		$view = $this->render("index");
		self::assertNull($view->document->querySelector("main admin-setup"));
		self::assertCount(7, $view->document->querySelectorAll("admin-sidebar .checklist li"));
		self::assertTrue($view->document->querySelector(".report")->hasAttribute("hidden"));
		$workspace = $this->workspace();
		$workspace->mutate(new Input(["operation" => "create-application", "name" => "Analytics"]));
		self::assertCount(1, $workspace->applications());
		$workspace->mutate(new Input(["operation" => "sender", "sender" => "new", "senderName" => "Analytics accounts", "senderEmail" => "accounts@example.test", "replyTo" => "support@example.test", "senderDomain" => "example.test"]));
		self::assertSame("Analytics accounts", $workspace->senders()[$workspace->changedSender]);
		$workspace->mutate(new Input(["operation" => "create-user", "name" => "Ada Example", "email" => "ada@example.test"]));
		self::assertCount(8, $workspace->users());
		$view = $this->render("users", ["search" => "ada", "reveal" => "yes"]);
		self::assertCount(1, $view->document->querySelectorAll(".record-table tbody tr"));
	}
	public function testApplicationAndDeploymentSettingsAndInvalidActions():void {
		$workspace = $this->workspace(["organisation" => "example", "application" => "console-test"]);
		$workspace->mutate(new Input(["operation" => "application", "entity" => "console-test", "name" => "Renamed", "production" => "staging"]));
		$workspace->mutate(new Input(["operation" => "deployment", "entity" => "console-test", "deployment" => "staging", "name" => "Preview", "host" => "preview.example.test", "clientUrl" => "https://client.example.test", "loginPath" => "/callback/"]));
		$app = $workspace->applications()["console-test"];
		self::assertSame("Renamed", $app["name"]);
		self::assertSame("Preview", $app["deployments"]["staging"]["name"]);
		self::assertSame("staging", $app["production"]);
		$this->expectException(\InvalidArgumentException::class);
		$workspace->mutate(new Input(["operation" => "application", "entity" => "operations", "name" => "Not in scope", "production" => "production"]));
	}
	public function testTemplateOverridesAreEscapedAndPreviewIsSandboxed():void {
		$workspace = $this->workspace(["application" => "operations"]);
		$workspace->mutate(new Input(["operation" => "template", "template" => "welcome", "templateSubject" => "Welcome", "templateContent" => '<h1>Hello {{email}}</h1><script>alert(1)</script>']));
		$view = $this->render("emails", ["template" => "welcome", "application" => "operations"]);
		self::assertSame('<h1>Hello {{email}}</h1><script>alert(1)</script>', $view->document->querySelector('[name="templateContent"]')->textContent);
		self::assertTrue($view->document->querySelector("iframe")->hasAttribute("sandbox"));
		self::assertNull($view->document->querySelector('main script:not([src])'));
		self::assertSame("Growth", $this->workspace()->settings()["plan"]);
	}
	public function testInvalidSettingsAreNotPartiallySaved():void {
		$workspace = $this->workspace();
		$workspace->mutate(new Input(["operation" => "sessions", "sessionTimeout" => "240", "idleTimeout" => "20"]));
		try {
			$workspace->mutate(new Input(["operation" => "sessions", "sessionTimeout" => "5", "idleTimeout" => "invalid"]));
			self::fail("Invalid settings should be rejected.");
		}
		catch(\InvalidArgumentException) {
			self::assertSame("240", $this->workspace()->settings()["sessionTimeout"]);
			self::assertSame("20", $this->workspace()->settings()["idleTimeout"]);
		}
	}
	public function testDashboardCardsHaveScopedTabsAndFilteredLinks():void {
		foreach(["users" => "User", "countries" => "Country", "devices" => "Device", "invalid" => "User"] as $group => $title) {
			$view = $this->render("index", ["topUsage" => $group]);
			self::assertNull($view->document->querySelector("admin-overview"));
			self::assertNull($view->document->querySelector("usage-chart"));
			self::assertCount(3, $view->document->querySelectorAll("main > .card-grid > * > section"));
			self::assertSame($title, $view->document->querySelector("admin-top-usage thead th")->textContent);
			self::assertCount(1, $view->document->querySelectorAll('admin-top-usage button[aria-pressed="true"]'));
			self::assertSame($group === "invalid" ? "users" : $group, $view->document->querySelector('admin-top-usage button[aria-pressed="true"]')->value);
			self::assertStringNotContainsString("sienna@example.test", $view->document->querySelector("main")->textContent);
			foreach(["admin-top-usage" => ["userSort" => "logins"], "admin-new-users" => ["userSort" => "created"], "admin-abandoned-users" => ["userStatus" => "abandoned", "userSort" => "created"]] as $component => $filters) {
				$url = $view->document->querySelector("$component footer a")->getAttribute("href");
				self::assertSame("/admin/users/", parse_url($url, PHP_URL_PATH));
				parse_str(parse_url($url, PHP_URL_QUERY), $query);
				foreach($filters + $this->workspace()->scope() as $key => $value) self::assertSame($value, $query[$key]);
			}
		}
		$view = $this->render("index");
		$times = $view->document->querySelectorAll("admin-new-users time");
		self::assertCount(5, $times);
		self::assertSame("Yesterday morning", $times[1]->textContent);
		self::assertNotEmpty($times[0]->getAttribute("datetime"));
		self::assertNotEmpty($times[0]->getAttribute("title"));
		self::assertCount(1, $view->document->querySelectorAll("admin-abandoned-users li"));
	}
}
