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

	public function testDeploymentPanels_emptyListHasClosedCreationPanelWithReadonlyFieldsPresent():void {
		$sut = $this->context([]);
		self::assertSame([], iterator_to_array($sut->productionOptions()));
		$panels = iterator_to_array($sut->deploymentPanels());
		self::assertCount(1, $panels);
		self::assertTrue($panels[0]->isNew);
		self::assertFalse($panels[0]->isOpen);
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

	public function testDeploymentPanels_rejectedInputReopensCreationWithoutOpeningAnotherPanel():void {
		$sut = $this->context([new ManagedDeployment("deployment", "Production", "host.test", "https://app.test", "/", "secret")]);
		$input = new \GT\Input\Input([], ["deploymentName" => "Staging", "clientUrl" => "https://app.test/path"]);

		$panels = iterator_to_array($sut->deploymentPanels($input, "Invalid client URL"));

		self::assertFalse($panels[0]->isOpen);
		self::assertSame("", $panels[0]->error);
		self::assertTrue($panels[1]->isOpen);
		self::assertSame("Staging", $panels[1]->name);
		self::assertSame("https://app.test/path", $panels[1]->clientUrl);
		self::assertSame("Invalid client URL", $panels[1]->error);
	}

	public function testDeploymentPanels_rejectedExistingDeploymentKeepsItsPanelOpen():void {
		$sut = $this->context([new ManagedDeployment("deployment", "Production", "host.test", "https://app.test", "/", "secret")]);
		$input = new \GT\Input\Input([], ["deploymentId" => "deployment", "deploymentName" => "Staging"]);

		$panels = iterator_to_array($sut->deploymentPanels($input, "Invalid client URL"));

		self::assertTrue($panels[0]->isOpen);
		self::assertSame("Staging", $panels[0]->name);
		self::assertSame("Invalid client URL", $panels[0]->error);
		self::assertFalse($panels[1]->isOpen);
		self::assertSame("", $panels[1]->error);
	}
}
