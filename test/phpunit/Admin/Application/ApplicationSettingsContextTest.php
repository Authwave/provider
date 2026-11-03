<?php

namespace Authwave\Test\Admin\Application;

use Authwave\Model\ApplicationRepository;
use Authwave\Admin\Application\ApplicationSettingsContext;
use Authwave\Admin\Application\ApplicationSettings;
use Authwave\Admin\Application\ManagedDeployment;
use Authwave\Model\User;
use PHPUnit\Framework\TestCase;

class ApplicationSettingsContextTest extends TestCase {
	private function context(array $deployments, ?string $production = null):ApplicationSettingsContext {
		$store = $this->createStub(ApplicationRepository::class);
		$store->method("get")->willReturn(new ApplicationSettings("app", "App", $production));
		$store->method("deployments")->willReturn($deployments);
		return new ApplicationSettingsContext($store, $this->createStub(User::class), "app", "deployment");
	}

	public function testDeploymentPanels_emptyListStillHasOneOpenCreationPanelWithReadonlyFieldsPresent():void {
		$sut = $this->context([]);
		self::assertSame([], iterator_to_array($sut->productionOptions()));
		$panels = iterator_to_array($sut->deploymentPanels());
		self::assertCount(1, $panels);
		self::assertTrue($panels[0]->isNew);
		self::assertTrue($panels[0]->isOpen);
		self::assertSame("", $panels[0]->identifier);
		self::assertSame("", $panels[0]->apiKey);
		self::assertSame("/", $panels[0]->clientLoginPath);
	}

	public function testDeploymentPanels_existingAndCreationPanelsShareTheSameShapeAndProductionIsSelected():void {
		$sut = $this->context([new ManagedDeployment("deployment", "Production", "host.test", "https://app.test", "/login/", "secret")], "deployment");
		$panels = iterator_to_array($sut->deploymentPanels());
		self::assertCount(2, $panels);
		self::assertSame(array_keys(get_object_vars($panels[0])), array_keys(get_object_vars($panels[1])));
		self::assertFalse($panels[0]->isNew);
		self::assertTrue($panels[0]->isOpen);
		self::assertSame("deployment_secret", $panels[0]->apiKey);
		self::assertSame("Confirm deleting Production", $sut->deletionPrompt("deployment")->message);
		self::assertNull($sut->deletionPrompt("foreign"));
		self::assertNull($sut->deletionPrompt(null));
		self::assertTrue($panels[1]->isNew);
		self::assertFalse($panels[1]->isOpen);
		$options = iterator_to_array($sut->productionOptions());
		self::assertCount(2, $options);
		self::assertFalse($options[0]->selected);
		self::assertTrue($options[1]->selected);
	}
}
