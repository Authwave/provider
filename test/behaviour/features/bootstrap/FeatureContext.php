<?php

use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\MinkExtension\Context\MinkContext;
use Behat\Testwork\Hook\Scope\AfterSuiteScope;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Gt\Config\ConfigFactory;
use Gt\Database\Connection\Settings;
use Gt\Database\Database;
use PHPUnit\Framework\Assert;

class FeatureContext extends MinkContext {
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

		$pid = trim(file_get_contents(__DIR__ . "/../../browser.pid"));
		if($pid) {
			exec("kill $pid");
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
			"clientKey" => "01234567890abcdef",
			"clientHost" => "http://localhost:8080",
			"clientLoginHost" => "http://localhost:8081",
		]);
	}

	private function setupBrowser():void {
		$browserCommand = "chrome";

		foreach(["chromium-browser", "chrome", "google-chrome", "google-chrome-browser"]
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
		echo "Executing: $cmd" . PHP_EOL;
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
			exec("mysqldump -h $host -P $port -u'$username' -p'$password' $schema > $file");
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
			exec("mysql -h $host -P $port -u'$username' -p'$password' $schema < $file");
			break;
		}

		echo "DONE" . PHP_EOL;
	}

	/** @Given I make the login action */
	public function iMakeTheLoginAction() {
		echo "Logging in ... " . PHP_EOL;
		$this->visitPath("/?cipher=0123456789abcdef&iv=12345678&path=/");
	}

}