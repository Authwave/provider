<?php

use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\MinkExtension\Context\MinkContext;
use Behat\Testwork\Hook\Scope\AfterSuiteScope;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Gt\Database\Database;
use PHPUnit\Framework\Assert;

class FeatureContext extends MinkContext {
	private Database $database;
	private int $appId;
	private int $deploymentId;

	/** @BeforeSuite */
	public static function beforeSuite(BeforeSuiteScope $scope):void {
		self::setupBrowser();
	}

	/** @AFterSuite */
	public static function afterSuite(AfterSuiteScope $scope):void {
		$pid = trim(file_get_contents(__DIR__ . "/../../browser.pid"));
		echo "Browser PID: $pid" . PHP_EOL;

		if($pid) {
			exec("kill $pid");
		}
	}

	/** @BeforeScenario */
	public function beforeScenario(BeforeScenarioScope $scope):void {
		$tags = $scope->getScenario()->getTags();
		$dbTags = array_filter(
			$tags,
			fn($i) => strpos($i, "db:") === 0
		);

		$this->setupDatabase($dbTags);
	}

	/** @AfterScenario */
	public function afterScenario(AfterScenarioScope $scope):void {

	}

	private function setupDatabase(array $dbTags):void {
		echo "Migrating ... ";
		exec(__DIR__ . "/../../../../vendor/bin/db-migrate -f", $output, $return);

		if($return === 0) {
			echo "done!" . PHP_EOL;
		}
		else {
			echo "error!" . PHP_EOL;
			exit(1);
		}

		$config = \Gt\Config\ConfigFactory::createForProject(
			realpath(__DIR__ . "/../../../.."),
			realpath(__DIR__ . "/../../../../vendor/phpgt/webengine/config.default.ini")
		);
		$settings = new \Gt\Database\Connection\Settings(
			$config->get("database.query_directory"),
			$config->get("database.driver"),
			$config->get("database.schema"),
			$config->get("database.host"),
			$config->get("database.port"),
			$config->get("database.username"),
			$config->get("database.password")
		);
		$this->database = new Gt\Database\Database($settings);

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

	private static function setupBrowser():void {
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

	/** @Given I make the login action */
	public function iMakeTheLoginAction() {
		echo "Logging in ... " . PHP_EOL;
		$this->visitPath("/?cipher=0123456789abcdef&iv=12345678&path=/");
	}

}