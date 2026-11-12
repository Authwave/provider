<?php
namespace Authwave\Test\Email;

use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use PHPUnit\Framework\TestCase;

class DeploymentSenderTest extends TestCase {
	public function testSenderUsesDeploymentHostWithoutPort():void {
		$app = new Application("app", "Example", "old@example.com");
		$deployment = new ApplicationDeployment("deployment", $app, "Production", "", "", "/",
			"login.example.com:8443", emailPrefix: "account");
		self::assertSame("account@login.example.com", $deployment->getSenderAddress());
	}

	public function testExistingDeploymentKeepsItsSender():void {
		$app = new Application("app", "Example", "old@example.com");
		$deployment = new ApplicationDeployment("deployment", $app, "Production", "", "", "/",
			"login.example.com");
		self::assertSame("old@example.com", $deployment->getSenderAddress());
	}
}
