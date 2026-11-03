<?php

namespace Authwave\Test\Admin\Application;

use Authwave\Model\ApplicationRepository;
use Authwave\Admin\Application\ApplicationSettingsAction;
use Authwave\Admin\Application\ApplicationSettingsContext;
use Authwave\Admin\Application\ApplicationSettings;
use Authwave\Admin\Application\ManagedDeployment;
use Authwave\Model\User;
use Authwave\Session\FlashSession;
use Authwave\Security\Audit;
use Authwave\Security\Action;
use GT\Input\Input;
use Gt\Http\Request;
use Gt\Http\Response;
use Gt\Http\Uri;
use PHPUnit\Framework\TestCase;

class ApplicationSettingsActionTest extends TestCase {
	private function request(string $method = "POST", string $path = "/admin/applications/app/"):Request {
		$request = $this->createStub(Request::class);
		$request->method("getMethod")->willReturn($method);
		$request->method("getUri")->willReturn(new Uri($path));
		return $request;
	}

	private function context(ApplicationRepository $store):ApplicationSettingsContext {
		$store->method("get")->willReturn(new ApplicationSettings("app", "Application", null));
		$store->method("deployments")->willReturn([new ManagedDeployment("existing", "Development", "host.test", "https://app.test", "/", "secret")]);
		return new ApplicationSettingsContext($store, $this->createStub(User::class), "app");
	}

	public function testSaveApplication_applicationUsesRouteIdentityAndIgnoresForgedReadonlyIdentifier():void {
		$store = $this->createMock(ApplicationRepository::class);
		$context = $this->context($store);
		$store->expects(self::once())->method("save")->with($context->actor, "app",
			self::callback(fn($name) => $name->value === "Renamed"), null);
		$response = $this->createMock(Response::class);
		$response->expects(self::once())->method("redirect")->with("/admin/applications/app/");
		$sut = new ApplicationSettingsAction($store, $context, $this->createStub(FlashSession::class), $this->createStub(Audit::class));
		$sut->saveApplication(new Input([], ["name" => "Renamed", "id" => "foreign"]), $this->request(), $response);
	}

	public function testSaveDeployment_deploymentIgnoresForgedApiKeyAndUsesValidatedData():void {
		$store = $this->createMock(ApplicationRepository::class);
		$context = $this->context($store);
		$store->expects(self::once())->method("saveDeployment")->with($context->actor, "app", "existing",
			self::callback(fn($data) => $data->name === "Development"))
			->willReturn(new ManagedDeployment("existing", "Development", "host.test", "https://app.test", "/", "unchanged"));
		$response = $this->createMock(Response::class);
		$response->expects(self::once())->method("redirect")->with("/admin/applications/app/?deployment=existing#deployment-existing");
		$sut = new ApplicationSettingsAction($store, $context, $this->createStub(FlashSession::class), $this->createStub(Audit::class));
		$sut->saveDeployment(new Input([], ["deploymentId" => "existing", "confirmDelete" => "existing", "deploymentName" => "Development", "host" => "host.test", "clientUrl" => "https://app.test", "clientLoginPath" => "/", "apiKey" => "attacker"]), $this->request(), $response);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("invalidApplications")]
	public function testSaveApplication_badAndMissingFieldsAreRejectedAndAuditedWithoutWriting(array $body):void {
		$store = $this->createMock(ApplicationRepository::class);
		$context = $this->context($store);
		$store->expects(self::never())->method("save");
		$audit = $this->createMock(Audit::class);
		$audit->expects(self::once())->method("create")->with(Action::APPLICATION_SETTINGS_REJECTED,
			self::anything(), $context->actor);
		$sut = new ApplicationSettingsAction($store, $context, $this->createStub(FlashSession::class), $audit);
		$sut->saveApplication(new Input([], $body), $this->request(), $this->createStub(Response::class));
	}

	public function testSaveApplication_getAndWrongRouteCannotWrite():void {
		$store = $this->createMock(ApplicationRepository::class);
		$context = $this->context($store);
		$store->expects(self::never())->method("save");
		$sut = new ApplicationSettingsAction($store, $context, $this->createStub(FlashSession::class), $this->createStub(Audit::class));
		$sut->saveApplication(new Input(), $this->request("GET"), $this->createStub(Response::class));
		$sut->saveApplication(new Input(), $this->request("POST", "/admin/applications/foreign/"), $this->createStub(Response::class));
	}

	public function testSaveApplication_databaseFailureNeverClaimsSuccess():void {
		$store = $this->createMock(ApplicationRepository::class);
		$context = $this->context($store);
		$store->expects(self::once())->method("save")->willThrowException(new \RuntimeException("failure"));
		$flash = $this->createMock(FlashSession::class);
		$flash->expects(self::never())->method("setFlash");
		$this->expectException(\RuntimeException::class);
		$sut = new ApplicationSettingsAction($store, $context, $flash, $this->createStub(Audit::class));
		$sut->saveApplication(new Input([], ["name" => "Name"]), $this->request(), $this->createStub(Response::class));
	}

	public function testDeleteDeployment_deletionUsesTheRouteApplicationAndDoesNotValidateEditableFields():void {
		$store = $this->createMock(ApplicationRepository::class);
		$context = $this->context($store);
		$store->expects(self::once())->method("deleteDeployment")->with($context->actor, "app", "existing");
		$store->expects(self::never())->method("saveDeployment");
		$flash = $this->createMock(FlashSession::class);
		$flash->expects(self::once())->method("setFlash")->with("Deployment deleted.", "settings_success");
		$response = $this->createMock(Response::class);
		$response->expects(self::once())->method("redirect")->with("/admin/applications/app/");
		$sut = new ApplicationSettingsAction($store, $context, $flash, $this->createStub(Audit::class));
		$sut->deleteDeployment(new Input([], ["deploymentId" => "existing", "confirmDelete" => "existing", "id" => "foreign", "host" => "invalid"]), $this->request(), $response);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("invalidDeletionIdentifiers")]
	public function testDeleteDeployment_deletionRejectsMissingBlankAndArrayIdentifiersAndAuditsTheAttempt(array $body):void {
		$store = $this->createMock(ApplicationRepository::class);
		$context = $this->context($store);
		$store->expects(self::never())->method("deleteDeployment");
		$audit = $this->createMock(Audit::class);
		$audit->expects(self::once())->method("create")->with(Action::DEPLOYMENT_DELETION_REJECTED,
			self::anything(), $context->actor);
		$sut = new ApplicationSettingsAction($store, $context, $this->createStub(FlashSession::class), $audit);
		$sut->deleteDeployment(new Input([], $body), $this->request(), $this->createStub(Response::class));
	}

	public function testDeleteDeployment_deletionGetAndWrongRouteCannotWrite():void {
		$store = $this->createMock(ApplicationRepository::class);
		$context = $this->context($store);
		$store->expects(self::never())->method("deleteDeployment");
		$sut = new ApplicationSettingsAction($store, $context, $this->createStub(FlashSession::class), $this->createStub(Audit::class));
		$sut->deleteDeployment(new Input([], ["deploymentId" => "existing", "confirmDelete" => "existing"]), $this->request("GET"), $this->createStub(Response::class));
		$sut->deleteDeployment(new Input([], ["deploymentId" => "existing", "confirmDelete" => "existing"]), $this->request("POST", "/admin/applications/foreign/"), $this->createStub(Response::class));
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("deletionFailures")]
	public function testDeleteDeployment_deletionRejectionsAreAuditedAndDatabaseErrorsCannotClaimSuccess(\Throwable $error):void {
		$store = $this->createMock(ApplicationRepository::class);
		$context = $this->context($store);
		$store->expects(self::once())->method("deleteDeployment")->willThrowException($error);
		$flash = $this->createMock(FlashSession::class);
		$audit = $this->createMock(Audit::class);
		if($error instanceof \InvalidArgumentException) {
			$flash->expects(self::once())->method("setFlash")->with("Cannot delete", "settings_error");
			$audit->expects(self::once())->method("create")->with(Action::DEPLOYMENT_DELETION_REJECTED,
				self::anything(), $context->actor);
		}
		else {
			$flash->expects(self::never())->method("setFlash");
			$audit->expects(self::never())->method("create");
		}
		try {
			$sut = new ApplicationSettingsAction($store, $context, $flash, $audit);
			$sut->deleteDeployment(new Input([], ["deploymentId" => "existing", "confirmDelete" => "existing"]), $this->request(), $this->createStub(Response::class));
			self::assertInstanceOf(\InvalidArgumentException::class, $error);
		}
		catch(\RuntimeException $caught) {
			self::assertSame($error, $caught);
		}
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("unconfirmedDeletions")]
	public function testDeleteDeployment_unconfirmedOrMismatchedDeletionOnlyRequestsConfirmation(mixed $confirmation):void {
		$store = $this->createMock(ApplicationRepository::class);
		$context = $this->context($store);
		$store->expects(self::never())->method("deleteDeployment");
		$flash = $this->createMock(FlashSession::class);
		$flash->expects(self::never())->method("setFlash");
		$audit = $this->createMock(Audit::class);
		$audit->expects(self::once())->method("create")->with(Action::DELETE_CONFIRMATION_REQUESTED,
			self::anything(), $context->actor);
		$response = $this->createMock(Response::class);
		$response->expects(self::once())->method("redirect")->with("/admin/applications/app/?confirm-delete=existing#delete-confirmation");
		$sut = new ApplicationSettingsAction($store, $context, $flash, $audit);
		$sut->deleteDeployment(
			new Input([], ["deploymentId" => "existing", "confirmDelete" => $confirmation]), $this->request(), $response);
	}

	public static function invalidApplications():iterable {
		foreach([["name" => []], [], ["name" => " "], ["productionDeploymentId" => []]] as $body) {
			yield [$body];
		}
	}

	public static function invalidDeletionIdentifiers():iterable {
		foreach([[], ["deploymentId" => ""], ["deploymentId" => " "], ["deploymentId" => []]] as $body) {
			yield [$body];
		}
	}

	public static function deletionFailures():iterable {
		foreach([new \InvalidArgumentException("Cannot delete"), new \RuntimeException("Audit failed")] as $error) {
			yield [$error];
		}
	}

	public static function unconfirmedDeletions():iterable {
		foreach([null, "other", ["existing"]] as $confirmation) {
			yield [$confirmation];
		}
	}
}
