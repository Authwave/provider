<?php
namespace Authwave\Test\Admin\Application;

use Authwave\Admin\Application\DeploymentPanel;
use Authwave\Admin\Application\ManagedDeployment;
use GT\Input\Input;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeploymentPanelTest extends TestCase {
	public function testConstruct_rejectedInputPreservesEditableFieldsAndSavedIdentity():void {
		$deployment = new ManagedDeployment("saved", "Development", "login.test", "https://client.test", "/", "secret");
		$input = new Input([], ["deploymentId" => "forged", "apiKey" => "forged",
			"deploymentName" => "<b>Staging</b>", "host" => "new-login.test",
			"clientUrl" => "https://client.test/not-an-origin", "clientLoginPath" => "/login/"]);
		$sut = new DeploymentPanel($deployment, true, $input, "Invalid client URL");

		self::assertSame("saved", $sut->identifier);
		self::assertSame("saved_secret", $sut->apiKey);
		self::assertSame("<b>Staging</b>", $sut->name);
		self::assertSame("new-login.test", $sut->host);
		self::assertSame("https://client.test/not-an-origin", $sut->clientUrl);
		self::assertSame("/login/", $sut->clientLoginPath);
		self::assertSame("Invalid client URL", $sut->error);
		self::assertTrue($sut->isOpen);
	}

	#[DataProvider("invalidFields")]
	public function testConstruct_missingOrNonStringValuesRemainEmpty(mixed $value):void {
		$sut = new DeploymentPanel(null, true, new Input([], ["host" => $value]));

		self::assertSame("", $sut->host);
		self::assertSame("", $sut->name);
		self::assertSame("", $sut->clientUrl);
		self::assertSame("", $sut->clientLoginPath);
	}

	public static function invalidFields():iterable {
		yield [null];
		yield [[]];
		yield [123];
		yield [false];
	}
}
