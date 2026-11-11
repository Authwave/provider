<?php

namespace Authwave\Test\Admin\Application;

use Authwave\Model\ApplicationRepository;
use Authwave\Admin\Application\ApplicationSettingsAction;
use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\Model\User;
use Authwave\Session\FlashSession;
use Authwave\Security\Audit;
use Authwave\Security\Action;
use GT\Input\Input;
use GT\Http\Request;
use GT\Http\Response;
use GT\Http\Uri;
use PHPUnit\Framework\TestCase;

class ApplicationSettingsActionTest extends TestCase {
	private function form():\GT\Dom\Element {
		$document = new \GT\Dom\HTMLDocument(file_get_contents("page/_component/application-deployments.html"));
		return $document->querySelector("form");
	}

	private function request(string $method = "POST", string $path = "/admin/applications/app/"):Request {
		$request = $this->createStub(Request::class);
		$request->method("getMethod")->willReturn($method);
		$request->method("getUri")->willReturn(new Uri($path));
		return $request;
	}

	private function application(ApplicationRepository $store):Application {
		$application = new Application("app", "Application", null);
		$store->method("deployments")->willReturn([new ApplicationDeployment("existing", $application,
			"Development", "secret", "https://app.test", "/", "host.test")]);
		return $application;
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("deletionTargets")]
	public function testDeletionPrompt_onlySavedDeploymentsCanBeConfirmed(?string $id, bool $expected):void {
		$store = $this->createStub(ApplicationRepository::class);
		$application = $this->application($store);
		$sut = new ApplicationSettingsAction($store, $application, $this->createStub(User::class),
			$this->createStub(FlashSession::class), $this->createStub(Audit::class));

		$prompt = $sut->deletionPrompt($id);

		self::assertSame($expected, $prompt !== null);
		if($prompt !== null) {
			self::assertSame("existing", $prompt->id);
			self::assertSame("Confirm deleting Development", $prompt->message);
		}
	}

	public static function deletionTargets():iterable {
		yield ["existing", true];
		yield ["foreign", false];
		yield ["", false];
		yield [null, false];
	}

	public function testSaveApplication_applicationUsesRouteIdentityAndIgnoresForgedReadonlyIdentifier():void {
		$store = $this->createMock(ApplicationRepository::class);
		$application = $this->application($store);
		$actor = $this->createStub(User::class);
		$store->expects(self::once())->method("save")->with($actor, "app",
			self::callback(fn($name) => $name->value === "Renamed"), null);
		$response = $this->createMock(Response::class);
		$response->expects(self::once())->method("redirect")->with("/admin/applications/app/");
		$sut = new ApplicationSettingsAction($store, $application, $actor, $this->createStub(FlashSession::class), $this->createStub(Audit::class));
		$sut->saveApplication(new Input([], ["name" => "Renamed", "id" => "foreign"]), $this->request(), $response);
	}

	public function testSaveDeployment_deploymentIgnoresForgedApiKeyAndUsesValidatedData():void {
		$store = $this->createMock(ApplicationRepository::class);
		$application = $this->application($store);
		$actor = $this->createStub(User::class);
		$store->expects(self::once())->method("saveDeployment")->with($actor, "app", "existing",
			self::callback(fn($data) => $data->title === "Development"))
			->willReturn(new ApplicationDeployment("existing", $application, "Development", "unchanged", "https://app.test", "/", "host.test"));
		$response = $this->createMock(Response::class);
		$response->expects(self::once())->method("redirect")->with("/admin/applications/app/?deployment=existing#deployment-existing");
		$sut = new ApplicationSettingsAction($store, $application, $actor, $this->createStub(FlashSession::class), $this->createStub(Audit::class));
		$sut->saveDeployment(new Input([], ["id" => "existing", "confirmDelete" => "existing", "title" => "Development", "providerHost" => "host.test", "clientHost" => "https://app.test", "clientLoginPath" => "/", "apiKey" => "attacker"]), $this->request(), $response, $this->form());
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("invalidApplications")]
	public function testSaveApplication_badAndMissingFieldsAreRejectedAndAuditedWithoutWriting(array $body):void {
		$store = $this->createMock(ApplicationRepository::class);
		$application = $this->application($store);
		$actor = $this->createStub(User::class);
		$store->expects(self::never())->method("save");
		$audit = $this->createMock(Audit::class);
		$audit->expects(self::once())->method("create")->with(Action::APPLICATION_SETTINGS_REJECTED,
			self::anything(), $actor);
		$sut = new ApplicationSettingsAction($store, $application, $actor, $this->createStub(FlashSession::class), $audit);
		$sut->saveApplication(new Input([], $body), $this->request(), $this->createStub(Response::class));
	}

	public function testSaveApplication_getAndWrongRouteCannotWrite():void {
		$store = $this->createMock(ApplicationRepository::class);
		$application = $this->application($store);
		$actor = $this->createStub(User::class);
		$store->expects(self::never())->method("save");
		$sut = new ApplicationSettingsAction($store, $application, $actor, $this->createStub(FlashSession::class), $this->createStub(Audit::class));
		$sut->saveApplication(new Input(), $this->request("GET"), $this->createStub(Response::class));
		$sut->saveApplication(new Input(), $this->request("POST", "/admin/applications/foreign/"), $this->createStub(Response::class));
	}

	public function testSaveApplication_databaseFailureNeverClaimsSuccess():void {
		$store = $this->createMock(ApplicationRepository::class);
		$application = $this->application($store);
		$actor = $this->createStub(User::class);
		$store->expects(self::once())->method("save")->willThrowException(new \RuntimeException("failure"));
		$flash = $this->createMock(FlashSession::class);
		$flash->expects(self::never())->method("setFlash");
		$this->expectException(\RuntimeException::class);
		$sut = new ApplicationSettingsAction($store, $application, $actor, $flash, $this->createStub(Audit::class));
		$sut->saveApplication(new Input([], ["name" => "Name"]), $this->request(), $this->createStub(Response::class));
	}

	public function testDeleteDeployment_deletionUsesTheRouteApplicationAndDoesNotValidateEditableFields():void {
		$store = $this->createMock(ApplicationRepository::class);
		$application = $this->application($store);
		$actor = $this->createStub(User::class);
		$store->expects(self::once())->method("deleteDeployment")->with($actor, "app", "existing");
		$store->expects(self::never())->method("saveDeployment");
		$flash = $this->createMock(FlashSession::class);
		$flash->expects(self::once())->method("setFlash")->with("Deployment deleted.", "settings_success");
		$response = $this->createMock(Response::class);
		$response->expects(self::once())->method("redirect")->with("/admin/applications/app/");
		$sut = new ApplicationSettingsAction($store, $application, $actor, $flash, $this->createStub(Audit::class));
		$sut->deleteDeployment(new Input([], ["id" => "existing", "confirmDelete" => "existing", "applicationId" => "foreign", "providerHost" => "invalid"]), $this->request(), $response);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("invalidDeletionIdentifiers")]
	public function testDeleteDeployment_deletionRejectsMissingBlankAndArrayIdentifiersAndAuditsTheAttempt(array $body):void {
		$store = $this->createMock(ApplicationRepository::class);
		$application = $this->application($store);
		$actor = $this->createStub(User::class);
		$store->expects(self::never())->method("deleteDeployment");
		$audit = $this->createMock(Audit::class);
		$audit->expects(self::once())->method("create")->with(Action::DEPLOYMENT_DELETION_REJECTED,
			self::anything(), $actor);
		$sut = new ApplicationSettingsAction($store, $application, $actor, $this->createStub(FlashSession::class), $audit);
		$sut->deleteDeployment(new Input([], $body), $this->request(), $this->createStub(Response::class));
	}

	public function testDeleteDeployment_deletionGetAndWrongRouteCannotWrite():void {
		$store = $this->createMock(ApplicationRepository::class);
		$application = $this->application($store);
		$actor = $this->createStub(User::class);
		$store->expects(self::never())->method("deleteDeployment");
		$sut = new ApplicationSettingsAction($store, $application, $actor, $this->createStub(FlashSession::class), $this->createStub(Audit::class));
		$sut->deleteDeployment(new Input([], ["id" => "existing", "confirmDelete" => "existing"]), $this->request("GET"), $this->createStub(Response::class));
		$sut->deleteDeployment(new Input([], ["id" => "existing", "confirmDelete" => "existing"]), $this->request("POST", "/admin/applications/foreign/"), $this->createStub(Response::class));
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("deletionFailures")]
	public function testDeleteDeployment_deletionRejectionsAreAuditedAndDatabaseErrorsCannotClaimSuccess(\Throwable $error):void {
		$store = $this->createMock(ApplicationRepository::class);
		$application = $this->application($store);
		$actor = $this->createStub(User::class);
		$store->expects(self::once())->method("deleteDeployment")->willThrowException($error);
		$flash = $this->createMock(FlashSession::class);
		$audit = $this->createMock(Audit::class);
		if($error instanceof \InvalidArgumentException) {
			$flash->expects(self::once())->method("setFlash")->with("Cannot delete", "settings_error");
			$audit->expects(self::once())->method("create")->with(Action::DEPLOYMENT_DELETION_REJECTED,
				self::anything(), $actor);
		}
		else {
			$flash->expects(self::never())->method("setFlash");
			$audit->expects(self::never())->method("create");
		}
		try {
			$sut = new ApplicationSettingsAction($store, $application, $actor, $flash, $audit);
			$sut->deleteDeployment(new Input([], ["id" => "existing", "confirmDelete" => "existing"]), $this->request(), $this->createStub(Response::class));
			self::assertInstanceOf(\InvalidArgumentException::class, $error);
		}
		catch(\RuntimeException $caught) {
			self::assertSame($error, $caught);
		}
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("unconfirmedDeletions")]
	public function testDeleteDeployment_unconfirmedOrMismatchedDeletionOnlyRequestsConfirmation(mixed $confirmation):void {
		$store = $this->createMock(ApplicationRepository::class);
		$application = $this->application($store);
		$actor = $this->createStub(User::class);
		$store->expects(self::never())->method("deleteDeployment");
		$flash = $this->createMock(FlashSession::class);
		$flash->expects(self::never())->method("setFlash");
		$audit = $this->createMock(Audit::class);
		$audit->expects(self::once())->method("create")->with(Action::DELETE_CONFIRMATION_REQUESTED,
			self::anything(), $actor);
		$response = $this->createMock(Response::class);
		$response->expects(self::once())->method("redirect")->with("/admin/applications/app/?confirm-delete=existing#delete-confirmation");
		$sut = new ApplicationSettingsAction($store, $application, $actor, $flash, $audit);
		$sut->deleteDeployment(
			new Input([], ["id" => "existing", "confirmDelete" => $confirmation]), $this->request(), $response);
	}

	public static function invalidApplications():iterable {
		foreach([["name" => []], [], ["name" => " "], ["productionApplicationDeploymentId" => []]] as $body) {
			yield [$body];
		}
	}

	public static function invalidDeletionIdentifiers():iterable {
		foreach([[], ["id" => ""], ["id" => " "], ["id" => []]] as $body) {
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

	#[\PHPUnit\Framework\Attributes\DataProvider("invalidDeployments")]
	public function testSaveDeployment_htmlConstraintsRejectInvalidFields(array $body):void {
		$store = $this->createMock(ApplicationRepository::class);
		$application = $this->application($store);
		$actor = $this->createStub(User::class);
		$store->expects(self::never())->method("saveDeployment");
		$audit = $this->createMock(Audit::class);
		$audit->expects(self::once())->method("create")->with(Action::APPLICATION_SETTINGS_REJECTED,
			self::callback(fn(array $detail) => $detail["applicationId"] === "app" && $detail["reason"] !== ""), $actor);
		$flash = $this->createMock(FlashSession::class);
		$flash->expects(self::once())->method("setFlash")->with(self::callback(fn($message) => is_string($message) && $message !== ""), "deployment_error");
		$response = $this->createMock(Response::class);
		$response->expects(self::never())->method("redirect");
		$sut = new ApplicationSettingsAction($store, $application, $actor, $flash, $audit);

		$sut->saveDeployment(new Input([], $body), $this->request(), $response, $this->form());
	}

	public static function invalidDeployments():iterable {
		$valid = ["title" => "Development", "providerHost" => "login.example.test",
			"clientHost" => "https://www.example.test", "clientLoginPath" => "/login/"];
		foreach([
			"title" => [null, "", " ", [], str_repeat("a", 33), "bad\nname", "\xFF"],
			"providerHost" => [null, "", "https://login.test", "login.test/path", "user@host.test", "bad host", "host:port", "host:100000", "[::1]", "host.test?query", str_repeat("a", 257)],
			"clientHost" => [null, "", "example.test", "javascript:alert(1)", "ftp://host.test", "https://user:pass@host.test", "https://host.test/path", "https://host.test?q=1", "https://host.test/#x", "https://[::1]"],
			"clientLoginPath" => [null, "", "login", "//evil.test", "/\\evil.test", "/bad%0dpath", "/bad?query", "/bad#fragment", "/bad%xy", str_repeat("/", 257)],
		] as $field => $values) {
			foreach($values as $value) {
				$body = $valid;
				$body[$field] = $value;
				yield [$body];
			}
		}
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("validDeployments")]
	public function testSaveDeployment_htmlConstraintsAcceptZeroAndUnicodeNames(string $name, string $host, string $url):void {
		$store = $this->createMock(ApplicationRepository::class);
		$application = $this->application($store);
		$actor = $this->createStub(User::class);
		$store->expects(self::once())->method("saveDeployment")
			->willReturn(new ApplicationDeployment("saved", $application, $name, "secret", $url, "/", $host));
		$sut = new ApplicationSettingsAction($store, $application, $actor, $this->createStub(FlashSession::class), $this->createStub(Audit::class));

		$sut->saveDeployment(new Input([], ["title" => $name, "providerHost" => $host,
			"clientHost" => $url, "clientLoginPath" => "/"]), $this->request(), $this->createStub(Response::class), $this->form());
	}

	public static function validDeployments():iterable {
		yield ["0", "localhost:65535", "http://localhost:8080/"];
		yield [str_repeat("馬", 32), "localhost:9105", "https://localhost:8080"];
		yield [str_repeat("😀", 16), "login.example.test", "HTTPS://CLIENT.TEST"];
		yield ["Development", "127.0.0.1:8080", "https://client.test"];
	}
}
