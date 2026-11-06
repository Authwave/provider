<?php
namespace Authwave\Test\View;

use Authwave\Model\{Application, ApplicationDeployment};
use Authwave\View\LoginBranding;
use GT\Dom\HTMLDocument;
use GT\DomTemplate\Binder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LoginBrandingTest extends TestCase {
	#[DataProvider("productionSelections")]
	public function testApply_usesApplicationIdentityAndIdentifiesNonProduction(?string $production,
		string $deploymentTitle, bool $badge):void {
		$application = new Application("branding-test-no-logo", "RealTest", null,
			productionApplicationDeploymentId: $production);
		$deployment = new ApplicationDeployment("deployment", $application, $deploymentTitle,
			"secret", "client.test", "/");
		$bindings = [];
		$binder = $this->createMock(Binder::class);
		$binder->expects(self::atLeastOnce())->method("bindKeyValue")->willReturnCallback(function(string $key, mixed $value)use(&$bindings):void {
			$bindings[$key] = $value;
		});
		$sut = new LoginBranding();

		$sut->apply(new HTMLDocument(), $binder, $deployment);

		self::assertSame("RealTest", $bindings["applicationName"]);
		self::assertSame("RealTest - Login", $bindings["title"]);
		self::assertSame($deploymentTitle, $bindings["deploymentName"]);
		self::assertSame($badge, $bindings["nonProduction"]);
	}

	public static function productionSelections():iterable {
		yield "production" => ["deployment", "Production", false];
		yield "development" => ["other-deployment", "Development", true];
		yield "no production selected" => [null, "Development", true];
	}
}
