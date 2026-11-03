<?php

use Authwave\Test\Behat\DatabaseState;
use Behat\Hook\AfterScenario;
use Behat\MinkExtension\Context\MinkContext;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Behat\Mink\Exception\ExpectationException;

require_once dirname(__DIR__) . "/database/DatabaseState.php";

class FeatureContext extends MinkContext {
	#[Then('the sidebar should own its semantic landmark')]
	public function sidebarLandmark():void {
		$this->assertSession()->elementsCount("css", "admin-sidebar", 1);
		$this->assertSession()->elementExists("css", 'body > admin-sidebar > aside[aria-label="Application sidebar"] nav[aria-label="Administration"]');
		$this->assertSession()->elementNotExists("css", "body > aside > admin-sidebar");
	}

	#[Then('unfinished controls should be disabled')]
	public function unfinishedControlsDisabled():void {
		$this->expect($this->getSession()->evaluateScript('(() => {
			const controls = [...document.querySelectorAll("main input:not([type=hidden]), main button, main select, main textarea")];
			return controls.length > 0 && controls.every(control => control.disabled);
		})()'), "Unfinished screens must not offer simulated actions.");
	}

	#[Then('prototype records should not be displayed')]
	public function noPrototypeRecords():void {
		$this->assertSession()->elementsCount("css", "main table tbody tr, main .record-list > li", 0);
		$this->assertSession()->elementsCount("css", 'main input[name="do"][value="demo"]', 0);
		$this->assertSession()->elementNotExists("css", 'a[download="sample-users.csv"]');
	}

	#[Then('the sidebar controls should fit narrow and wide screens')]
	public function sidebarLayout():void {
		foreach([1440, 390, 280] as $width) {
			$this->getSession()->resizeWindow($width, 1000);
			$this->openSidebar();
			$this->expect($this->getSession()->evaluateScript('(() => {
				const header = document.querySelector(".sidebar-header").getBoundingClientRect();
				const nav = document.querySelector("nav[aria-label=Administration]").getBoundingClientRect();
				const account = document.querySelector(".sidebar-account").getBoundingClientRect();
				return nav.top >= header.bottom && account.top >= nav.bottom
					&& document.documentElement.scrollWidth <= document.documentElement.clientWidth;
			})()'), "Sidebar layout failed at $width pixels.");
		}
	}

	#[Then('the sidebar should provide the correct scripting fallback')]
	public function sidebarScriptingFallback():void {
		$this->expect($this->getSession()->evaluateScript('(() => {
			const scriptsEnabled = matchMedia("(scripting: enabled)").matches;
			const submit = document.querySelector(".sidebar-header button[type=submit]");
			const theme = document.querySelector("[data-theme-toggle]");
			return (getComputedStyle(submit).display === "none") === scriptsEnabled
				&& theme.hidden === !scriptsEnabled;
		})()'), "Scope and theme controls must respect scripting availability.");
	}

	#[Given('the client login destination is the local logged-out page')]
	public function localClientLoginDestination():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("update ApplicationDeployment set clientHost = ?, clientLoginPath = '/logged-out/' where id = 'provider'");
		$query->execute([rtrim($this->getMinkParameter("base_url"), "/")]);
	}

	#[Then('the client login redirect should be audited')]
	public function auditedClientRedirect():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$row = $database->query("select detail, anonUserId from Audit where action = 'CLIENT_LOGIN_REDIRECTED'")->fetch(PDO::FETCH_ASSOC);
		$this->expect($row !== false, "Missing client login redirect audit.");
		$detail = json_decode($row["detail"], flags: JSON_THROW_ON_ERROR);
		$this->expect($detail->redirectTo === rtrim($this->getMinkParameter("base_url"), "/") . "/logged-out/",
			"Incorrect client redirect destination in audit.");
		$this->expect($detail->deploymentId === "provider" && $row["anonUserId"] !== null,
			"Missing deployment or anonymous actor in redirect audit.");
	}

	private ?DatabaseState $database = null;

	#[Given('the database is in the :state state')]
	public function databaseState(string $state):void {
		$this->getSession()->reset();
		$path = getenv("BEHAT_DATABASE");
		if(!$path) throw new RuntimeException("Run Behat using composer behat.");
		$this->database = new DatabaseState($path, dirname(__DIR__) . "/database");
		$this->database->apply($state);
		$connection = new PDO("sqlite:$path");
		$host = parse_url($this->getMinkParameter("base_url"), PHP_URL_HOST);
		$port = parse_url($this->getMinkParameter("base_url"), PHP_URL_PORT);
		$update = $connection->prepare("update ApplicationDeployment set providerHost = ? where id = 'provider'");
		$update->execute([$host . ($port ? ":$port" : "")]);
		$update = $connection->prepare("update User set hash = ? where id = 'administrator'");
		$update->execute([password_hash("Test-admin-password-2026", PASSWORD_DEFAULT)]);
	}

	#[AfterScenario]
	public function restoreDatabase():void {
		$this->getSession()->stop();
		$this->database?->restore();
	}

	#[Given('I sign in as the fixture administrator')]
	public function signIn():void {
		$this->visit("/admin/");
		$this->fillField("Email address", "admin@example.test");
		$this->pressButton("Continue");
		$this->getSession()->wait(5000, "document.querySelector('input[name=password]') !== null");
		$this->fillField("Password", "Test-admin-password-2026");
		$this->pressButton("Log in with password");
		$this->getSession()->wait(5000, "document.querySelector('admin-sidebar') !== null");
	}

	#[Then('the provider path should be :path')]
	public function providerPath(string $path):void {
		$this->getSession()->wait(5000, "location.pathname === " . json_encode($path));
		$actual = parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH);
		$this->expect($path === $actual, "Expected path $path, got $actual.");
	}

	#[Then('the account setup should show :complete of :total complete')]
	public function setupProgress(int $complete, int $total):void {
		$progress = $this->getSession()->getPage()->find('css', '.setup-card progress');
		$this->expect($progress !== null, "The account setup progress meter is missing.");
		$this->expect((string)$complete === $progress->getAttribute('value'),
			"Expected $complete completed steps, got " . $progress->getAttribute('value'));
		$this->expect((string)$total === $progress->getAttribute('max'), "Unexpected setup step total.");
	}

	#[Then('the first application note should be highlighted')]
	public function highlightedNote():void {

		$this->expect(parse_url($this->getSession()->getCurrentUrl(), PHP_URL_FRAGMENT) === 'first-application',
			'The redirect did not target the first application note.');
		$this->assertSession()->elementExists('css', '#first-application.onboarding-note');
	}

	#[When('I follow the administration link :link')]
	public function followAdminLink(string $link):void {
		$this->openSidebar();
		$this->getSession()->getPage()->find('css', 'nav[aria-label="Administration"]')->clickLink($link);
	}

	#[When('I press the sidebar logout button')]
	public function logout():void {
		$this->openSidebar();
		$this->getSession()->getPage()->find('css', 'form[data-admin-logout]')->pressButton('Log out');
	}

	#[When('I continue account setup')]
	public function continueSetup():void {
		$this->openSidebar();
		$this->clickLink("Continue setup");
	}

	private function openSidebar():void {
		$menu = $this->getSession()->getPage()->find('css', '.sidebar-menu');
		if(!$menu->hasAttribute('open') && $menu->find('css', 'summary')->isVisible()) {
			$menu->find('css', 'summary')->click();
		}
	}

	#[Then('the setup guide should contain :count steps')]
	public function guideCount(int $count):void {
		$this->assertSession()->elementsCount('css', 'setup-guide details', $count);
	}

	#[Then('only setup step :step should be open')]
	public function currentStep(int $step):void {
		$this->assertSession()->elementsCount('css', 'setup-guide details[open]', 1);
		$this->assertSession()->elementExists('css', "setup-guide details[open][data-step='$step']");
	}

	#[When('I open setup step :step')]
	public function openStep(int $step):void {
		$this->getSession()->getPage()->find('css', "setup-guide details[data-step='$step'] summary")->click();
	}
	#[Then('I should see :text in the application switcher')]
	public function applicationSwitcherText(string $text):void {
		$select = $this->assertSession()->elementExists('css', 'application-switcher select');
		$this->expect($select->isVisible(), 'The application switcher should remain visible.');
		$this->assertSession()->elementTextContains('css', 'application-switcher select', $text);
	}

	#[Then('there should be :count entry in the application switcher')]
	#[Then('there should be :count entries in the application switcher')]
	public function applicationSwitcherEntryCount(int $count):void {
		$this->assertSession()->elementsCount('css', 'application-switcher select option', $count);
	}

	#[Then('the application :name should be saved for the fixture administrator')]
	public function savedApplication(string $name):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select count(*) from Application join UserAdmin on Application.id = UserAdmin.applicationId where UserAdmin.userId = 'administrator' and Application.name = ?");
		$query->execute([$name]);
		$this->expect((int)$query->fetchColumn() === 1, 'The application and admin assignment were not persisted.');
	}

	#[Then('the fixture administrator should have :count saved applications')]
	public function savedApplicationCount(int $count):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$actual = $database->query("select count(*) from UserAdmin where userId = 'administrator'")->fetchColumn();
		$this->expect((int)$actual === $count, 'Unexpected number of saved applications.');
	}

	#[Then('the application switcher should select :name')]
	public function selectedApplication(string $name):void {
		$this->getSession()->wait(5000,
			"document.querySelector('.sidebar-header option[selected]')?.textContent === " . json_encode($name));
		$select = $this->assertSession()->elementExists('css', '.sidebar-header select');
		$selected = $select->find('css', 'option[selected]');
		$this->expect($selected?->getText() === $name,
			'Expected application ' . $name . ', selected ' . ($selected?->getText() ?? '(none)')
			. '. Options: ' . $select->getHtml());
	}

	#[Then('no application name markup should be interpreted as HTML')]
	public function literalApplicationName():void {
		$this->assertSession()->elementNotExists('css', '.application-heading strong');
	}

	#[When('I switch to application :name')]
	public function switchApplication(string $name):void {
		$select = $this->assertSession()->elementExists('css', '.sidebar-header select');
		$select->selectOption($name);
		$this->getSession()->getPage()->find('css', '.sidebar-header')->pressButton('Apply scope');
	}

	private function expect(bool $condition, string $message):void {
		if(!$condition) throw new ExpectationException($message, $this->getSession()->getDriver());
	}

	#[Then('action :action should be audited for the fixture administrator')]
	public function auditedAction(string $action):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select detail from Audit where action = ? and userId = 'administrator'");
		$query->execute([$action]);
		$row = $query->fetch(PDO::FETCH_ASSOC);
		$this->expect($row !== false, "Missing administrator audit record for $action.");
		$detail = $row['detail'] === null ? null
			: json_decode($row['detail'], flags: JSON_THROW_ON_ERROR);
		$this->expect($detail === null || (is_object($detail)
			&& !property_exists($detail, "diff") && !property_exists($detail, "context")),
			"Audit details must not contain duplicate envelopes.");
	}

	#[Then('creation of application :name should be audited')]
	public function auditedApplication(string $name):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->query("select detail from Audit where action = 'APPLICATION_CREATED' and userId = 'administrator'");
		$records = $query->fetchAll(PDO::FETCH_COLUMN);
		$this->expect(count($records) === 1, "Expected exactly one creation audit record.");
		$detail = json_decode($records[0], flags: JSON_THROW_ON_ERROR);
		$this->expect(!property_exists($detail, "from"), "Creation should have no previous state.");
		$this->expect($detail->to->name === $name, "Incorrect application name in audit.");
		$query = $database->prepare("select name from Application where id = ?");
		$query->execute([$detail->to->applicationId]);
		$this->expect($query->fetchColumn() === $name, "Audit does not identify the saved application.");
	}

	private ?string $savedDeploymentKey = null;

	#[Then('the breadcrumb should show :parent followed by :current')]
	public function breadcrumb(string $parent, string $current):void {
		$nav = $this->assertSession()->elementExists('css', 'nav[aria-label="Breadcrumb"]');
		$this->expect($nav->find('css', 'a')?->getText() === $parent, 'Incorrect parent breadcrumb.');
		$this->expect($nav->find('css', 'span[aria-current="page"]')?->getText() === $current,
			'Incorrect current breadcrumb.');
	}

	private ?array $applicationHeadingLayout = null;

	private function applicationHeadingLayout():array {
		$this->getSession()->wait(5000, "document.fonts.status === 'loaded'");
		return $this->getSession()->evaluateScript("(() => {
			const link = document.querySelector('.application-heading a');
			const rect = link.getBoundingClientRect();
			const style = getComputedStyle(link);
			return {x:rect.left + scrollX, y:rect.top + scrollY, width:rect.width, height:rect.height,
				font:style.fontFamily, size:style.fontSize, weight:style.fontWeight, lineHeight:style.lineHeight};
		})()");
	}

	#[When('I record the Applications heading at a viewport width of :width')]
	public function recordApplicationHeading(int $width):void {
		$this->getSession()->resizeWindow($width, 1000);
		$this->applicationHeadingLayout = $this->applicationHeadingLayout();
	}

	#[Then('the Applications heading should keep its position and style')]
	public function consistentApplicationHeading():void {
		$actual = $this->applicationHeadingLayout();
		$this->expect($this->applicationHeadingLayout !== null, 'Missing reference heading layout.');
		foreach($actual as $key => $value) {
			$expected = $this->applicationHeadingLayout[$key];
			$this->expect(is_numeric($value) ? abs($value - $expected) < 0.5 : $value === $expected,
				"Application heading $key changed: expected $expected, got $value.");
		}
	}

	#[When('I follow :text in the breadcrumb')]
	public function followBreadcrumb(string $text):void {
		$nav = $this->assertSession()->elementExists('css', 'nav[aria-label="Breadcrumb"]');
		$link = $nav->findLink($text);
		$this->expect($link !== null, 'Missing breadcrumb link.');
		$path = parse_url($link->getAttribute('href'), PHP_URL_PATH);
		$link->click();
		$this->getSession()->wait(5000, 'location.pathname === ' . json_encode($path));
	}
	private ?string $deletedDeploymentId = null;
	private ?string $deletedDeploymentKey = null;

	#[When('I request deletion of deployment :summary')]
	public function deleteDeployment(string $summary):void {
		$details = $this->deploymentDetails($summary);
		$fields = $details->findAll('css', 'input[readonly]');
		$this->deletedDeploymentId = $fields[0]->getValue();
		$this->deletedDeploymentKey = $fields[1]->getValue();
		$details->pressButton('Delete deployment');
		$this->getSession()->wait(5000,
			"document.querySelector('#delete-dialog[open], #delete-confirmation:not([hidden])') !== null");
	}

	#[Then('the deleted deployment should have an audit snapshot without its API key')]
	public function auditedDeletedDeployment():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select detail from Audit where action = 'DEPLOYMENT_DELETED' and userId = 'administrator'");
		$query->execute();
		$records = $query->fetchAll(PDO::FETCH_COLUMN);
		$this->expect(count($records) === 1, "Expected exactly one deployment deletion audit.");
		$detail = json_decode($records[0], flags: JSON_THROW_ON_ERROR);
		$this->expect($detail->from->identifier === $this->deletedDeploymentId && !property_exists($detail, "to"),
			"Missing deleted deployment details.");
		$this->expect($detail->applicationId === 'managed', "Incorrect application scope.");
		$this->expect($this->deletedDeploymentKey !== null
			&& !str_contains($records[0], $this->deletedDeploymentKey), "API key leaked into audit.");
		$this->expect(!property_exists($detail->from, "secret") && !property_exists($detail->from, "apiKey"),
			"Credentials must be omitted.");
		$query = $database->prepare("select count(*) from ApplicationDeployment where id = ?");
		$query->execute([$this->deletedDeploymentId]);
		$this->expect((int)$query->fetchColumn() === 0, "Deployment was not deleted.");
	}

	#[Then('the production deployment should be unassigned and its removal audited')]
	public function clearedProductionDeployment():void {
		$this->expect($this->getSession()->getPage()->findField('Production deployment')->getValue() === '',
			"Production selection was not cleared.");
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$this->expect($database->query("select productionApplicationDeploymentId from Application where id = 'managed'")->fetchColumn() === null,
			"Stored production selection was not cleared.");
		$records = $database->query("select detail from Audit where action = 'APPLICATION_UPDATED' and userId = 'administrator'")->fetchAll(PDO::FETCH_COLUMN);
		foreach($records as $record) {
			$detail = json_decode($record);
			$change = $detail->from->productionDeploymentId ?? null;
			if($change === $this->deletedDeploymentId && $detail->to->productionDeploymentId === null) return;
		}
		throw new RuntimeException("Missing production-selection removal audit.");
	}

	#[Then("the deployment's dependent :table record should remain")]
	public function dependentDeploymentRecord(string $table):void {
		if(!in_array($table, ['User', 'Email'], true)) throw new InvalidArgumentException('Unknown table.');
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$column = $table === 'User' ? 'applicationDeploymentId' : 'deploymentId';
		$query = $database->prepare("select count(*) from $table where $column = ?");
		$query->execute([$this->deletedDeploymentId]);
		$this->expect((int)$query->fetchColumn() === 1, "Dependent record was removed.");
		$this->expect((int)$database->query("select count(*) from Audit where action = 'DEPLOYMENT_DELETED'")->fetchColumn() === 0,
			"A rejected deletion must not be recorded as successful.");
	}

	#[When('I submit invalid application data :case')]
	public function submitInvalidApplicationData(string $case):void {
		$this->getSession()->executeScript('(() => {
			const input = document.querySelector("application-create input[name=name]");
			const form = input.form;
			const kind = ' . json_encode($case) . ';
			input.value = kind === "long" ? "x".repeat(129) : "Application";
			if(kind === "missing") input.removeAttribute("name");
			if(kind === "array") input.name = "name[]";
			form.noValidate = true;
			form.requestSubmit(form.querySelector("button[type=submit]"));
		})()');
		$this->getSession()->wait(5000,
			'document.querySelector("application-create [role=alert]:not([hidden])") !== null');
	}

	#[Then('application settings should fit a viewport of :width by :height')]
	#[Then('the applications table should fit a viewport of :width by :height')]
	public function settingsViewport(int $width, int $height):void {
		$this->getSession()->resizeWindow($width, $height);
		$this->expect($this->getSession()->evaluateScript(
			'document.documentElement.scrollWidth <= document.documentElement.clientWidth'),
			"Application settings overflow at $width pixels.");
		if($directory = getenv("UI_SCREENSHOT_DIR")) {
			if(!is_dir($directory)) mkdir($directory, 0777, true);
			$prefix = $this->getSession()->getPage()->find('css', 'application-list')
				? 'applications-list' : 'application-settings';
			$this->saveScreenshot("$prefix-$width.png", $directory);
		}
	}

	#[Then('the applications table should contain:')]
	public function applicationsTable(\Behat\Gherkin\Node\TableNode $table):void {
		$this->getSession()->resizeWindow(1440, 1000);
		$actual = [];
		$headings = $this->getSession()->getPage()->findAll('css', 'application-list thead th');
		$actual[] = array_map(fn($heading) => $heading->getText(), $headings);
		foreach($this->getSession()->getPage()->findAll('css', 'application-list tbody tr') as $row) {
			$actual[] = array_map(fn($cell) => $cell->getText(), $row->findAll('css', 'td'));
		}
		$this->expect($actual === $table->getRows(), 'Unexpected applications table: ' . json_encode($actual));
	}

	#[When('I follow :text in the applications table')]
	public function followApplicationTableLink(string $text):void {
		$table = $this->assertSession()->elementExists('css', 'application-list table');
		$link = $table->findLink($text);
		$this->expect($link !== null, 'Missing application settings link.');
		$path = parse_url($link->getAttribute('href'), PHP_URL_PATH);
		$link->click();
		$this->getSession()->wait(5000, 'location.pathname === ' . json_encode($path));
	}

	#[Then('the applications list should precede the create panel and adapt to the screen')]
	public function applicationListLayout():void {
		$this->expect($this->getSession()->evaluateScript(
			'Boolean(document.querySelector("application-list").compareDocumentPosition(document.querySelector("application-create")) & Node.DOCUMENT_POSITION_FOLLOWING)'),
			'The list must precede the create panel in the HTML.');
		foreach([1440, 390] as $width) {
			$this->getSession()->resizeWindow($width, 1000);
			$sideBySide = $width === 1440 ? 'true' : 'false';
			$this->expect($this->getSession()->evaluateScript('(() => {
				const list = document.querySelector("application-list section").getBoundingClientRect();
				const create = document.querySelector("application-create section").getBoundingClientRect();
				return ' . $sideBySide . '
					? create.left >= list.right && Math.abs(create.top - list.top) < 2
					: create.top >= list.bottom;
			})()'), "Unexpected application panel layout at $width pixels.");
		}
	}

	#[Then('every application table cell should link to its row target')]
	public function clickableApplicationCells():void {
		$table = $this->assertSession()->elementExists('css', 'application-list table.table-link');
		$this->expect(count($table->findAll('css', 'thead a')) === 0, 'Column headings should not be linked.');
		foreach($table->findAll('css', 'tbody tr') as $row) {
			$source = $row->find('css', 'a[data-table-link]');
			$this->expect($source !== null, 'Missing row source link.');
			$target = $source->getAttribute('href');
			$this->expect($source->getAttribute('tabindex') === null, 'Original link must stay keyboard accessible.');
			$this->expect($row->getAttribute('data-clickable-href') === null, 'Row target must not be duplicated.');
			$this->expect($target !== null && $target !== '', 'Missing row target.');
			foreach($row->findAll('css', 'td') as $cell) {
				$links = $cell->findAll('css', 'a');
				$this->expect(count($links) === 1 && $links[0]->getAttribute('href') === $target,
					'Every cell must have exactly one link to its application.');
				if(!$links[0]->hasAttribute('data-table-link')) {
					$this->expect($links[0]->getAttribute('tabindex') === '-1', 'Additional links must skip tab order.');
				}
			}
		}
		$this->expect(count($table->findAll('css', 'a a')) === 0, 'Nested links are invalid.');
	}

	#[When('I follow the :column cell for application :name')]
	public function followApplicationCell(string $column, string $name):void {
		foreach($this->getSession()->getPage()->findAll('css', 'application-list tbody tr') as $row) {
			if($row->find('css', 'td[data-label="Name"]')->getText() !== $name) continue;
			$cell = $row->find('css', 'td[data-label="' . $column . '"]');
			$this->expect($cell !== null, 'Missing application cell.');
			$path = $row->find('css', 'a[data-table-link]')->getAttribute('href');
			$cell->click();
			$this->getSession()->wait(5000, 'location.pathname === ' . json_encode($path));
			return;
		}
		throw new RuntimeException('Missing application row.');
	}

	#[Then('I should be on the settings page for application :name')]
	public function applicationSettingsPage(string $name):void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select Application.id from Application join UserAdmin on UserAdmin.applicationId = Application.id where Application.name = ? and UserAdmin.userId = 'administrator'");
		$query->execute([$name]);
		$identifier = $query->fetchColumn();
		$this->expect(is_string($identifier), "Application was not persisted.");
		$path = "/admin/applications/$identifier/";
		$this->getSession()->wait(5000, "location.pathname === " . json_encode($path));
		$this->assertPageAddress($path);
	}

	#[Then('the application settings should show its read-only ID and name :name')]
	public function applicationSettingsFields(string $name):void {
		$form = $this->assertSession()->elementExists('css', 'application-settings form');
		$identifier = $form->find('css', 'input[readonly]');
		$this->expect($identifier !== null && $identifier->getValue() !== "", "Missing read-only application ID.");
		$this->expect($form->findField('Name')->getValue() === $name, "Incorrect application name.");
	}

	#[Then('the production deployment select should have :count options')]
	public function productionOptionCount(int $count):void {
		$this->assertSession()->elementsCount('css', 'application-settings select option', $count);
	}

	#[Then('there should be no open deployment disclosures')]
	public function noOpenDeploymentDisclosures():void {
		$this->assertSession()->elementsCount('css', 'application-deployments details[open]', 0);
	}

	#[Then('the create deployment disclosure should be compact with a plus icon')]
	public function compactDeploymentDisclosure():void {
		$this->expect($this->getSession()->evaluateScript('(() => {
			const details = document.querySelector(".deployment-list > [data-new=\\"1\\"] > details");
			const summary = details.querySelector("summary");
			const icon = summary.querySelector("[data-icon=plus]");
			const rect = details.getBoundingClientRect();
			const parent = details.parentElement.getBoundingClientRect();
			return getComputedStyle(details).display === "inline-block"
				&& Math.abs((rect.left + rect.right) - (parent.left + parent.right)) < 2
				&& details.querySelector("button[value=save-deployment]").classList.contains("primary")
				&& !document.querySelector("application-settings button").classList.contains("primary")
				&& !document.querySelector(".deployment-list > :not([data-new=\\"1\\"]) button[value=save-deployment]").classList.contains("primary")
				&& getComputedStyle(summary).backgroundColor === getComputedStyle(details.querySelector("button[value=save-deployment]")).backgroundColor
				&& details.getBoundingClientRect().width < details.parentElement.getBoundingClientRect().width
				&& getComputedStyle(summary).listStyleType === "none"
				&& getComputedStyle(icon).display !== "none"
				&& getComputedStyle(icon, "::before").maskImage.includes("plus.svg")
				&& getComputedStyle(document.querySelector(".deployment-list > :not([data-new=\\"1\\"]) summary")).display === "list-item";
		})()'), 'Create deployment should have a compact plus disclosure; saved deployments keep their markers.');
	}

	#[When('I open deployment :summary')]
	public function openDeployment(string $summary):void {
		$this->deploymentDetails($summary);
	}

	#[When('I close deployment :summary')]
	public function closeDeployment(string $summary):void {
		$this->deploymentDetails($summary)->find('css', 'summary')->click();
	}

	#[Then('only deployment :summary should be open')]
	public function onlyDeploymentOpen(string $summary):void {
		$this->assertSession()->elementsCount('css', 'application-deployments details[open]', 1);
		$open = $this->assertSession()->elementExists('css', 'application-deployments details[open]');
		$this->expect($open->find('css', 'summary')->getText() === $summary,
			'Unexpected open deployment.');
	}

	#[Then('application form actions should reserve an input label and gap above their buttons')]
	public function applicationFormActionSpacing():void {
		$this->expect($this->getSession()->evaluateScript('(() => {
			const form = document.querySelector("application-settings form");
			const label = form.querySelector("label");
			const actions = form.querySelector(".actions");
			const expected = label.querySelector("span").getBoundingClientRect().height
				+ parseFloat(getComputedStyle(label).rowGap);
			return [...form.querySelectorAll("button")].every(button => actions.contains(button))
				&& Math.abs(parseFloat(getComputedStyle(actions).paddingBlockStart) - expected) < 2;
		})()'), 'Action container spacing must match the input label line and field gap.');
	}

	#[Then('application settings should precede deployments and adapt to the screen')]
	public function applicationSettingsLayout():void {
		$this->expect($this->getSession()->evaluateScript(
			'Boolean(document.querySelector("application-settings").compareDocumentPosition(document.querySelector("application-deployments")) & Node.DOCUMENT_POSITION_FOLLOWING)'),
			'Settings must precede deployments in the HTML.');
		foreach([1440, 390, 280] as $width) {
			$this->getSession()->resizeWindow($width, 1000);
			$sideBySide = $width === 1440 ? 'true' : 'false';
			$this->expect($this->getSession()->evaluateScript('(() => {
				const settings = document.querySelector("application-settings section").getBoundingClientRect();
				const deployments = document.querySelector("application-deployments section").getBoundingClientRect();
				return ' . $sideBySide . '
					? deployments.left >= settings.right && Math.abs(deployments.top - settings.top) < 2
					: deployments.top >= settings.bottom;
			})()'), "Unexpected settings panel layout at $width pixels.");
			$this->settingsViewport($width, 1000);
		}
	}

	#[Then('there should be :count deployment expander')]
	#[Then('there should be :count deployment expanders')]
	public function deploymentPanelCount(int $count):void {
		$this->assertSession()->elementsCount('css', 'application-deployments ul > li > details', $count);
	}

	#[Then('the new deployment form should retain hidden read-only ID and API key fields')]
	public function emptyDeploymentFields():void {
		$fields = $this->getSession()->getPage()->findAll('css', 'application-deployments li[data-new="1"] input[readonly]');
		$this->expect(count($fields) === 2, "Both read-only fields must remain in the HTML.");
		foreach($fields as $field) {
			$this->expect(!$field->isVisible() && $field->getValue() === "", "New deployment fields should be hidden and empty.");
		}
	}

	private function deploymentDetails(string $summary):\Behat\Mink\Element\NodeElement {
		foreach($this->getSession()->getPage()->findAll('css', 'application-deployments details') as $details) {
			if($details->find('css', 'summary')->getText() !== $summary) continue;
			if(!$details->hasAttribute('open')) $details->find('css', 'summary')->click();
			return $details;
		}
		throw new RuntimeException("Missing deployment expander: $summary");
	}

	#[When('I complete the :summary deployment form:')]
	public function fillDeployment(string $summary, \Behat\Gherkin\Node\TableNode $table):void {
		$details = $this->deploymentDetails($summary);
		foreach($table->getRows() as [$field, $value]) $details->fillField($field, $value);
	}

	#[When('I save deployment :summary')]
	public function saveDeployment(string $summary):void {
		$details = $this->deploymentDetails($summary);
		$details->find('css', 'button[type=submit]')->press();
		$this->getSession()->wait(5000, "document.querySelector('application-settings [role=status]:not([hidden]), application-settings [role=alert]:not([hidden])') !== null");
	}

	#[Then('deployment :summary should have a saved read-only ID and API key')]
	public function savedDeploymentFields(string $summary):void {
		$details = $this->deploymentDetails($summary);
		$fields = $details->findAll('css', 'input[readonly]');
		$this->expect(count($fields) === 2, "Missing read-only deployment fields.");
		foreach($fields as $field) $this->expect($field->isVisible() && $field->getValue() !== "", "Deployment fields should be visible and populated.");
		$this->savedDeploymentKey = $fields[1]->getValue();
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select id, secret from ApplicationDeployment where id = ?");
		$query->execute([$fields[0]->getValue()]);
		$row = $query->fetch(PDO::FETCH_ASSOC);
		$this->expect($row && $this->savedDeploymentKey === $row['id'] . '_' . $row['secret'], "The deployment/API key was not persisted.");
	}

	#[Then('deployment :summary should keep the same API key')]
	public function stableDeploymentKey(string $summary):void {
		$details = $this->deploymentDetails($summary);
		$fields = $details->findAll('css', 'input[readonly]');
		$this->expect($this->savedDeploymentKey !== null && $fields[1]->getValue() === $this->savedDeploymentKey, "Editing must not rotate the API key.");
	}

	#[Then('production should use deployment :name')]
	public function productionDeployment(string $name):void {
		$select = $this->getSession()->getPage()->findField('Production deployment');
		$this->expect($select->find('css', 'option[selected]')?->getText() === $name, "Incorrect production option.");
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select title from ApplicationDeployment where id = (select productionApplicationDeploymentId from Application where id = ApplicationDeployment.applicationId) and id = ?");
		$query->execute([$select->getValue()]);
		$this->expect($query->fetchColumn() === $name, "Production selection was not persisted.");
	}

	private function deletionConfirmation():\Behat\Mink\Element\NodeElement {
		return $this->assertSession()->elementExists('css', '#delete-dialog[open], #delete-confirmation:not([hidden])');
	}

	#[Then('I should see :message in the deletion confirmation')]
	public function deletionConfirmationMessage(string $message):void {
		$this->expect($this->deletionConfirmation()->find('css', 'h2')->getText() === $message,
			'Incorrect deletion confirmation message.');
		$this->noDeploymentDeleted();
		if(getenv('BEHAT_JAVASCRIPT') === '1') {
			$this->expect($this->getSession()->evaluateScript("document.querySelector('#delete-dialog').matches(':modal')"),
				'Deletion should use a native modal dialog.');
			$this->expect($this->getSession()->evaluateScript('document.activeElement.value') === 'cancel',
				'Cancel should have initial focus.');
		}
		if($directory = getenv('UI_SCREENSHOT_DIR')) {
			if(!is_dir($directory)) mkdir($directory, 0777, true);
			$this->saveScreenshot('delete-confirmation.png', $directory);
		}
	}

	#[When('I dismiss the deletion confirmation')]
	public function dismissDeletion():void {
		$confirmation = $this->deletionConfirmation();
		if($confirmation->getTagName() === 'dialog') {
			$cancel = $confirmation->findButton('Cancel');
			$cancel->keyDown(27);
			$cancel->keyUp(27);
		}
		else $confirmation->clickLink('Cancel');
		$this->getSession()->wait(5000, "document.querySelector('#delete-dialog[open], #delete-confirmation:not([hidden])') === null");
	}

	#[When('I confirm deletion')]
	public function confirmDeletion():void {
		$this->deletionConfirmation()->pressButton('Confirm delete');
		$this->getSession()->wait(5000,
			"document.querySelector('application-settings [role=status]')?.textContent === 'Deployment deleted.' || document.querySelector('application-settings [role=alert]:not([hidden])') !== null");
	}

	#[Then('the deletion confirmation name should not contain HTML elements')]
	public function deletionConfirmationPlainText():void {
		$this->expect($this->deletionConfirmation()->find('css', 'h2 *') === null,
			'The deletion name must be escaped text.');
	}

	#[When('I cancel deletion')]
	public function cancelDeletion():void {
		$confirmation = $this->deletionConfirmation();
		if($confirmation->getTagName() === 'dialog') $confirmation->pressButton('Cancel');
		else $confirmation->clickLink('Cancel');
		$this->getSession()->wait(5000, "document.querySelector('#delete-dialog[open], #delete-confirmation:not([hidden])') === null");
	}

	#[Then('no deployment should have been deleted')]
	public function noDeploymentDeleted():void {
		$database = new PDO("sqlite:" . getenv("BEHAT_DATABASE"));
		$query = $database->prepare("select count(*) from ApplicationDeployment where id = ?");
		$query->execute([$this->deletedDeploymentId]);
		$this->expect((int)$query->fetchColumn() === 1, 'Deployment was deleted before confirmation.');
		$this->expect((int)$database->query("select count(*) from Audit where action = 'DEPLOYMENT_DELETED'")->fetchColumn() === 0,
			'Unconfirmed deletion must not be audited as successful.');
	}

}
