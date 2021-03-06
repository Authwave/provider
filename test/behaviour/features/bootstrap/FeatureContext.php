<?php

use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\MinkExtension\Context\MinkContext;
use Behat\Testwork\Hook\Scope\AfterSuiteScope;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Gt\Config\ConfigFactory;
use Gt\Database\Connection\Settings;
use Gt\Database\Database;
use Gt\Http\Uri;
use PHPUnit\Framework\Assert;

class FeatureContext extends MinkContext {
	const TEST_API_KEY = "0123456789abcdef";

	private Database $database;
	private int $appId;
	private int $deploymentId;

	/** @BeforeSuite */
	public static function beforeSuite(BeforeSuiteScope $scope):void {
		self::backupDatabase();
	}

	/** @AFterSuite */
	public static function afterSuite(AfterSuiteScope $scope):void {
		self::restoreDatabase();
	}

	/** @BeforeScenario */
	public function beforeScenario(BeforeScenarioScope $scope):void {
		$tags = $scope->getScenario()->getTags();
		$dbTags = array_filter(
			$tags,
			fn($i) => strpos($i, "db:") === 0
		);

		$this->setupDatabase($dbTags);
		$this->setupBrowser();

	}

	/** @AfterScenario */
	public function afterScenario(AfterScenarioScope $scope):void {
		$this->getSession()->stop();

		$browserPidFile = __DIR__ . "/../../browser.pid";
		$pid = trim(file_get_contents($browserPidFile));
		if($pid) {
			exec("kill $pid");
			unlink($browserPidFile);
// TODO: Kill isn't instant, but probably doesn't take 1 second. What is the proper way to wait?
			sleep(1);
		}
	}

	private function setupDatabase(array $dbTags):void {
		exec(__DIR__ . "/../../../../vendor/bin/db-migrate -f", $output, $return);
		$this->database = self::getDatabaseInstance();

		if(in_array("db:no-data", $dbTags)) {
			echo "No data in this scenario." . PHP_EOL;
			return;
		}

		$this->appId = $this->database->insert(
			"application/create",
			"test-app"
		);
		$this->deploymentId = $this->database->insert(
			"application/createDeployment", [
			"applicationId" => $this->appId,
			"clientKey" => self::TEST_API_KEY,
			"clientHost" => "http://localhost:8080",
			"clientLoginHost" => "http://localhost:9105",
		]);
	}

	private function setupBrowser():void {
		$browserCommand = "chrome";

		foreach(["chromium-browser", "chrome", "google-chrome", "google-chrome-browser", "google-chrome-stable"]
		as $browserName) {
			exec("which $browserName", $output);
			if(!empty($output)) {
				$browserCommand = $output[0];
			}
		}

		$profileName = uniqid("authwave-");
		$tmpDir = sys_get_temp_dir() . "/authwave/test/profile/$profileName";
		$cmd = implode(" ", [
			__DIR__ . "/../../start-browser.bash",
			$browserCommand,
			$tmpDir,
		]);
		exec($cmd);
	}

	private static function getDatabaseInstance():Database {
		$settings = self::getDatabaseSettings();
		return new Database($settings);
	}

	private static function getDatabaseSettings():Settings {
		$config = ConfigFactory::createForProject(
			realpath(__DIR__ . "/../../../.."),
			realpath(__DIR__ . "/../../../../vendor/phpgt/webengine/config.default.ini")
		);
		return new Settings(
			$config->get("database.query_directory"),
			$config->get("database.driver"),
			$config->get("database.schema"),
			$config->get("database.host"),
			$config->get("database.port"),
			$config->get("database.username"),
			$config->get("database.password")
		);
	}

	private static function setupTestDatabase():void {
		$settings = self::getDatabaseSettings();
		$host = $settings->getHost();
		$port = $settings->getPort();
		$username = $settings->getUsername();
		$password = $settings->getPassword();
		$schema = $settings->getSchema();
		$file = realpath(__DIR__ . "/db/basic.sql");

		echo "Loading test database: $file ... ";

		switch($settings->getDriver()) {
		case Settings::DRIVER_MYSQL:
			exec("mysql -h $host -P $port -u'$username' -p'$password' $schema < $file");
			break;

		default:
			die("Error loading test database - unknown driver" . PHP_EOL);
		}

		echo "DONE" . PHP_EOL;
	}

	private static function backupDatabase():void {
		$settings = self::getDatabaseSettings();
		$host = $settings->getHost();
		$port = $settings->getPort();
		$username = $settings->getUsername();
		$password = $settings->getPassword();
		$schema = $settings->getSchema();
		$file = realpath(__DIR__ . "/../..") . "/dump.sql";

		echo "Backing up database to $file ... ";

		switch($settings->getDriver()) {
		case Settings::DRIVER_MYSQL:
			exec("mysqldump -h $host -P $port -u'$username' -p'$password' $schema > $file 2>/dev/null");
			break;

		default:
			die("Error backing up database - unknown driver" . PHP_EOL);
		}

		echo "DONE" . PHP_EOL;
	}

	private static function restoreDatabase():void {
		$settings = self::getDatabaseSettings();
		$host = $settings->getHost();
		$port = $settings->getPort();
		$username = $settings->getUsername();
		$password = $settings->getPassword();
		$schema = $settings->getSchema();
		$file = realpath(__DIR__ . "/../..") . "/dump.sql";

		echo "Restoring database from $file ... ";

		switch($settings->getDriver()) {
		case Settings::DRIVER_MYSQL:
			exec("mysql -h $host -P $port -u'$username' -p'$password' $schema < $file 2>/dev/null");
			break;
		}

		unlink($file);
		echo "DONE" . PHP_EOL;
	}

	/** @Given I make the login action on the client application */
	public function iMakeTheLoginAction() {
		echo "Logging in ... " . PHP_EOL;

		$secretIv = "Dummy Login";
		$iv = random_bytes(16);

		$cipher = openssl_encrypt(
			$secretIv,
			"aes128",
			self::TEST_API_KEY,
			0,
			$iv
		);

		$uri = "/?" . http_build_query([
			"c" => base64_encode($cipher),
			"i" => bin2hex($iv),
			"p" => bin2hex("/"),
		]);
		$this->visitPath($uri);
	}

	/** @Then /^I should be on the client application$/ */
	public function iShouldBeOnTheClientApplication() {
		$uri = new Uri($this->getSession()->getCurrentUrl());
		$baseUri = new Uri($this->getMinkParameter("base_url"));
		Assert::assertNotSame($uri->getAuthority(), $baseUri->getAuthority());
	}

	/** @Given /^I go to the provider$/ */
	public function iGoToTheProvider() {
		$this->getSession()->restart();
		$this->getSession()->visit($this->getMinkParameter("base_url"));
	}

	/** @Given /^I should see the confirmed email as (.*)$/ */
	public function iShouldSeeTheConfirmedEmailAs(string $email) {
		$emailLink = $this->getSession()->getPage()->find(
			"css",
			"a[href='/login?email']"
		);
		Assert::assertEquals($email, $emailLink->getText());
	}

	/** @When I follow the confirmed email link */
	public function iFollowTheConfirmedEmailLink() {
		$emailLink = $this->getSession()->getPage()->find(
			"css",
			"a[href='/login?email']"
		);
		$emailLink->click();
	}

	/** @Given /^I should see an "([^"]*)" flash message reading "([^"]*)"$/ */
	public function iShouldSeeAnFlashMessageReading(
		string $type,
		string $message
	) {
		$flashElements = $this->getSession()->getPage()->findAll(
			"css",
			".flash-container .$type p"
		);

		$matchingMessages = [];

		foreach($flashElements as $flashElement) {
			$matchingMessages []= $flashElement->getText();
		}

		Assert::assertContains($message, $matchingMessages);
	}

}