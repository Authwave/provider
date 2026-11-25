<?php
namespace Authwave\Test\Model;

use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApplicationDeploymentTest extends TestCase {
	#[DataProvider("deploymentNames")]
	public function testGetApiKey_usesDeploymentName(string $title, string $prefix):void {
		$deployment = new ApplicationDeployment("DEPLOYMENT_ABCDEF",
			new Application("app", "App", "sender@example.test"), $title, "secret", "app.test", "/");
		self::assertSame($prefix . "_ABCDEF_secret", $deployment->getApiKey());
	}

	public static function deploymentNames():array {
		return [["Production", "PRODUCTION"], ["Staging", "STAGING"],
			["Pre_production", "PRE-PRODUCTION"], ["Test $1", "TEST $1"]];
	}
}
