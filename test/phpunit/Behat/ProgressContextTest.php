<?php
namespace Authwave\Test\Behat;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\{ExampleTableNode, FeatureNode, OutlineNode, ScenarioNode};
use Behat\Testwork\Environment\Environment;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . "/behat/bootstrap/ProgressContext.php";

class ProgressContextTest extends TestCase {
	public function testOutline_reportsExpandedScenarioNameAndExamplePosition():void {
		$path = tempnam(sys_get_temp_dir(), "authwave-progress-test-");
		$previous = getenv("BEHAT_PROGRESS_FILE");
		putenv("BEHAT_PROGRESS_FILE=$path");
		try {
			$outline = new OutlineNode("Open <name>", [], [],
				new ExampleTableNode([7 => ["name"], 8 => ["Production"], 9 => ["Staging"]], "Examples"), "Scenario Outline", 5);
			$feature = new FeatureNode("Deployments", null, [], null,
				[new ScenarioNode("Create deployment", [], [], "Scenario", 2), $outline],
				"Feature", "en", "/test/deployments.feature", 1);
			$scope = new BeforeScenarioScope($this->createStub(Environment::class), $feature, $outline->getExamples()[1]);
			(new \ProgressContext())->scenario($scope);
			$event = json_decode(trim(file_get_contents($path)), true);
			self::assertSame("Open Staging #2", $event["title"]);
			self::assertSame(3, $event["scenario"]);
			self::assertSame(3, $event["scenarios"]);
			self::assertSame("/test/deployments.feature", $event["feature"]);
		}
		finally {
			putenv($previous === false ? "BEHAT_PROGRESS_FILE" : "BEHAT_PROGRESS_FILE=$previous");
			unlink($path);
		}
	}
}
