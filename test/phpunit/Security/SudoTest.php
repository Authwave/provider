<?php
namespace Authwave\Test\Security;

use Authwave\Model\{Application, ApplicationDeployment, User};
use Authwave\Security\{Action, Audit, Sudo};
use Authwave\User\UserRepository;
use GT\Session\{Session, SessionStore};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use GT\Dom\HTMLDocument;
use Gt\Http\Uri;

class SudoTest extends TestCase {
	private function actor(string $id = "admin"):User {
		$app = new Application("app", "Authwave", null);
		$deployment = new ApplicationDeployment("provider", $app, "Provider", "secret", "client.test", "/");
		return new User($id, $deployment, "$id@example.test");
	}

	private function store():SessionStore {
		return new SessionStore("sudo", $this->createStub(Session::class));
	}

	private function fieldDocument():HTMLDocument {
		return new HTMLDocument('<html><body><details id="deployment-one"><form><label class="existing"><span>API key</span><input name="apiKey" readonly value="DEPLOYMENT_one_abcdefgh" data-sudo="key" data-copy></label></form></details></body></html>');
	}

	public function testApply_masksBeforeRenderingAndAddsNativeViewOutsideLabel():void {
		$document = $this->fieldDocument();
		$sut = new Sudo($this->store(), $this->createStub(UserRepository::class), $this->createStub(Audit::class));

		$sut->apply($document, $this->actor(), new Uri("/admin/applications/one/?deployment=one"));

		self::assertStringNotContainsString("DEPLOYMENT_one_abcdefgh", $document->documentElement->outerHTML);
		self::assertSame("DEPLOYMENT_one_abcd••••", $document->querySelector("input")->value);
		self::assertSame("apiKey", $document->querySelector("input")->name);
		self::assertNull($document->querySelector("label button"));
		self::assertSame("deployment-one-apiKey", $document->querySelector("label")->getAttribute("for"));
		$view = $document->querySelector("button");
		self::assertSame("sudo-controls", $view->getAttribute("form"));
		self::assertSame("view", $view->value);
		self::assertStringContainsString("return=%2Fadmin%2Fapplications%2Fone%2F%3Fdeployment%3Done", $view->getAttribute("formaction"));
		self::assertNull($document->querySelector("[data-sudo-enabled]"));
	}

	public function testApply_authorisedRenderCarriesMaskAndExpiryAndOpensDisclosure():void {
		$document = $this->fieldDocument();
		$users = $this->createStub(UserRepository::class);
		$users->method("checkLogin")->willReturn(true);
		$sut = new Sudo($this->store(), $users, $this->createStub(Audit::class), clock: fn():int => 100);
		$actor = $this->actor();
		$sut->request($actor, "deployment-one-apiKey", "/admin/applications/one/", "view");
		$sut->authenticate($actor, "password");

		$sut->apply($document, $actor, new Uri("/admin/applications/one/"));

		$field = $document->querySelector("input");
		self::assertSame("DEPLOYMENT_one_abcdefgh", $field->value);
		self::assertSame("DEPLOYMENT_one_abcd••••", $field->getAttribute("data-sudo-enabled"));
		self::assertSame("160", $field->getAttribute("data-sudo-until"));
		self::assertTrue($document->querySelector("details")->hasAttribute("open"));
		self::assertFalse($sut->reveal($actor, $field->id, "/admin/applications/one/"));
	}

	public function testApply_textControlsAreMaskedAndExistingIdsPreserved():void {
		$document = new HTMLDocument('<html><body><output id="private" data-sudo>secret</output></body></html>');
		$sut = new Sudo($this->store(), $this->createStub(UserRepository::class), $this->createStub(Audit::class));

		$sut->apply($document, $this->actor(), new Uri("/admin/"));

		self::assertSame("••••••", $document->querySelector("output")->textContent);
		self::assertSame("private", $document->querySelector("output")->id);
		self::assertSame("private", $document->querySelector("button")->getAttribute("data-sudo-view"));
	}

	#[DataProvider("returnActions")]
	public function testDestination_onlyAuthorisedViewCarriesFieldIntent(string $action, string $query):void {
		$users = $this->createStub(UserRepository::class);
		$users->method("checkLogin")->willReturn(true);
		$sut = new Sudo($this->store(), $users, $this->createStub(Audit::class));
		$actor = $this->actor();
		$return = "/admin/applications/one/?deployment=one&search=hello%20world";
		$sut->request($actor, "deployment-one-apiKey", $return . "&sudo-view=other", $action);
		self::assertSame($return, (string)$sut->destination($actor));

		$sut->authenticate($actor, "password");

		self::assertSame($return . $query, (string)$sut->destination($actor));
	}

	public static function returnActions():iterable {
		yield ["view", "&sudo-view=deployment-one-apiKey"];
		yield ["copy", ""];
	}

	public function testApply_viewIntentUsesTheExistingOneRenderGrant():void {
		$users = $this->createStub(UserRepository::class);
		$users->method("checkLogin")->willReturn(true);
		$sut = new Sudo($this->store(), $users, $this->createStub(Audit::class));
		$actor = $this->actor();
		$sut->request($actor, "deployment-one-apiKey", "/admin/applications/one/", "view");
		$sut->authenticate($actor, "password");
		$uri = $sut->destination($actor);
		$document = $this->fieldDocument();

		$sut->apply($document, $actor, $uri);

		self::assertSame("DEPLOYMENT_one_abcdefgh", $document->querySelector("input")->value);
		self::assertStringNotContainsString("sudo-view", $document->querySelector("button")->getAttribute("formaction"));

		$reloaded = $this->fieldDocument();
		$sut->apply($reloaded, $actor, $uri);
		self::assertSame("DEPLOYMENT_one_abcd••••", $reloaded->querySelector("input")->value);
	}

	public function testApply_queryStringAloneCannotRevealAKey():void {
		$sut = new Sudo($this->store(), $this->createStub(UserRepository::class), $this->createStub(Audit::class));
		$document = $this->fieldDocument();

		$sut->apply($document, $this->actor(), new Uri("/admin/applications/one/?sudo-view=deployment-one-apiKey"));

		self::assertSame("DEPLOYMENT_one_abcd••••", $document->querySelector("input")->value);
		self::assertNull($document->querySelector("[data-sudo-enabled]"));
	}

	public function testRequest_requiresPasswordUntilAuthorisedAndConsumesOnlyMatchingFieldOnce():void {
		$users = $this->createStub(UserRepository::class);
		$users->method("checkLogin")->willReturn(true);
		$sut = new Sudo($this->store(), $users, $this->createStub(Audit::class));
		$actor = $this->actor();

		self::assertSame("/login/sudo/", (string)$sut->request($actor, "key", "/admin/applications/app/", "view"));
		self::assertFalse($sut->reveal($actor, "key", "/admin/applications/app/"));
		self::assertTrue($sut->authenticate($actor, "password"));
		self::assertFalse($sut->reveal($actor, "other", "/admin/applications/app/"));
		self::assertFalse($sut->reveal($actor, "key", "/admin/applications/other/"));
		self::assertTrue($sut->reveal($actor, "key", "/admin/applications/app/"));
		self::assertFalse($sut->reveal($actor, "key", "/admin/applications/app/"));
		self::assertSame("/admin/applications/app/", (string)$sut->request($actor, "key", "/admin/applications/app/", "copy"));
		self::assertTrue($sut->reveal($actor, "key", "/admin/applications/app/"));
	}

	public function testAuthenticate_wrongPasswordDoesNotElevateOrChangePassword():void {
		$users = $this->createMock(UserRepository::class);
		$actor = $this->actor();
		$users->expects(self::once())->method("checkLogin")->with($actor, "wrong")->willReturn(false);
		$users->expects(self::never())->method("generateAuthCode");
		$audit = $this->createMock(Audit::class);
		$audit->expects(self::exactly(2))->method("create")->willReturnCallback(
			function(Action $action, ?array $detail) {
				self::assertContains($action, [Action::SUDO_REQUESTED, Action::SUDO_REJECTED]);
				self::assertStringNotContainsString("wrong", json_encode($detail));
			});
		$sut = new Sudo($this->store(), $users, $audit);
		$sut->request($actor, "key", "/admin/", "view");

		self::assertFalse($sut->authenticate($actor, "wrong"));
		self::assertSame(0, $sut->expiresAt($actor));
		self::assertFalse($sut->reveal($actor, "key", "/admin/"));
	}

	public function testAuthenticate_withoutRequestIsRejected():void {
		$users = $this->createMock(UserRepository::class);
		$users->expects(self::never())->method("checkLogin");
		$sut = new Sudo($this->store(), $users, $this->createStub(Audit::class));

		$this->expectException(\LogicException::class);
		$sut->authenticate($this->actor(), "password");
	}

	public function testExpiresAt_configuredDurationExpiresAtExactBoundary():void {
		$now = 100;
		$users = $this->createStub(UserRepository::class);
		$users->method("checkLogin")->willReturn(true);
		$sut = new Sudo($this->store(), $users, $this->createStub(Audit::class), 15,
			function()use(&$now):int { return $now; });
		$actor = $this->actor();
		$sut->request($actor, "key", "/admin/", "copy");
		$sut->authenticate($actor, "password");

		$now = 114;
		self::assertSame(115, $sut->expiresAt($actor));
		self::assertSame(0, $sut->expiresAt($this->actor("other")));
		$now = 115;
		self::assertSame(0, $sut->expiresAt($actor));
		self::assertFalse($sut->reveal($actor, "key", "/admin/"));
		self::assertSame("/login/sudo/", (string)$sut->request($actor, "key", "/admin/", "copy"));
	}

	public function testDisable_revokesPendingRevealAndAuthorisation():void {
		$users = $this->createStub(UserRepository::class);
		$users->method("checkLogin")->willReturn(true);
		$sut = new Sudo($this->store(), $users, $this->createStub(Audit::class));
		$actor = $this->actor();
		$sut->request($actor, "key", "/admin/", "view");
		$sut->authenticate($actor, "password");
		$sut->disable($actor);

		self::assertSame(0, $sut->expiresAt($actor));
		self::assertFalse($sut->pending($actor));
		self::assertFalse($sut->reveal($actor, "key", "/admin/"));
	}

	#[DataProvider("auditFailures")]
	public function testAuthenticate_auditFailureCannotEnableSudo(Action $failure):void {
		$users = $this->createStub(UserRepository::class);
		$users->method("checkLogin")->willReturn(true);
		$audit = $this->createStub(Audit::class);
		$audit->method("create")->willReturnCallback(function(Action $action)use($failure) {
			if($action === $failure) {
				throw new RuntimeException("Audit failed");
			}
		});
		$sut = new Sudo($this->store(), $users, $audit);
		$actor = $this->actor();

		try {
			$sut->request($actor, "key", "/admin/", "view");
			$sut->authenticate($actor, "password");
			self::fail("Expected audit failure");
		}
		catch(RuntimeException) {
			self::assertSame(0, $sut->expiresAt($actor));
			self::assertFalse($sut->reveal($actor, "key", "/admin/"));
		}
	}

	public static function auditFailures():iterable {
		yield [Action::SUDO_REQUESTED];
		yield [Action::SUDO_AUTHORISED];
	}

	public function testReveal_auditFailureCannotDiscloseField():void {
		$users = $this->createStub(UserRepository::class);
		$users->method("checkLogin")->willReturn(true);
		$audit = $this->createStub(Audit::class);
		$audit->method("create")->willReturnCallback(function(Action $action) {
			if($action === Action::SUDO_FIELD_ACCESSED) {
				throw new RuntimeException("Audit failed");
			}
		});
		$sut = new Sudo($this->store(), $users, $audit);
		$actor = $this->actor();
		$sut->request($actor, "key", "/admin/", "view");
		$sut->authenticate($actor, "password");

		$this->expectException(RuntimeException::class);
		$sut->reveal($actor, "key", "/admin/");
	}

	public function testRequest_anotherAdministratorCannotInheritAnActivePeriod():void {
		$users = $this->createStub(UserRepository::class);
		$users->method("checkLogin")->willReturn(true);
		$sut = new Sudo($this->store(), $users, $this->createStub(Audit::class));
		$sut->request($this->actor(), "key", "/admin/", "view");
		$sut->authenticate($this->actor(), "password");

		$other = $this->actor("other");
		self::assertFalse($sut->pending($other));
		self::assertFalse($sut->reveal($other, "key", "/admin/"));
		self::assertSame("/login/sudo/", (string)$sut->request($other, "key", "/admin/", "copy"));
		self::assertSame(0, $sut->expiresAt($other));
	}

	public function testReveal_defaultFieldsAreAvailableThroughoutTheActivePeriod():void {
		$users = $this->createStub(UserRepository::class);
		$users->method("checkLogin")->willReturn(true);
		$sut = new Sudo($this->store(), $users, $this->createStub(Audit::class));
		$actor = $this->actor();
		self::assertFalse($sut->reveal($actor, "private", "/admin/", ""));
		$sut->request($actor, "private", "/admin/", "view");
		$sut->authenticate($actor, "password");

		self::assertTrue($sut->reveal($actor, "private", "/admin/", ""));
		self::assertTrue($sut->reveal($actor, "private", "/admin/", ""));
		self::assertFalse($sut->pending($actor));
		self::assertFalse($sut->reveal($actor, "key", "/admin/", "key"));
	}

	public function testReturnUri_preservesQueryParameters():void {
		$sut = new Sudo($this->store(), $this->createStub(UserRepository::class), $this->createStub(Audit::class));
		self::assertSame("/admin/users/?search=hello%20world", (string)$sut->returnUri("/admin/users/?search=hello%20world"));
	}

	public function testAuthenticate_defaultPeriodIsSixtySeconds():void {
		$users = $this->createStub(UserRepository::class);
		$users->method("checkLogin")->willReturn(true);
		$sut = new Sudo($this->store(), $users, $this->createStub(Audit::class), clock: fn():int => 100);
		$actor = $this->actor();
		$sut->request($actor, "key", "/admin/", "view");
		$sut->authenticate($actor, "password");

		self::assertSame(160, $sut->expiresAt($actor));
	}

	public function testDisable_auditFailureStillRevokesAccess():void {
		$users = $this->createStub(UserRepository::class);
		$users->method("checkLogin")->willReturn(true);
		$audit = $this->createStub(Audit::class);
		$audit->method("create")->willReturnCallback(function(Action $action) {
			if($action === Action::SUDO_DISABLED) {
				throw new RuntimeException("Audit failed");
			}
		});
		$sut = new Sudo($this->store(), $users, $audit);
		$actor = $this->actor();
		$sut->request($actor, "key", "/admin/", "view");
		$sut->authenticate($actor, "password");

		try {
			$sut->disable($actor);
			self::fail("Expected audit failure");
		}
		catch(RuntimeException) {
			self::assertSame(0, $sut->expiresAt($actor));
			self::assertFalse($sut->pending($actor));
		}
	}

	#[DataProvider("invalidRequests")]
	public function testRequest_rejectsMissingOrMalformedTargets(string $field, string $action):void {
		$sut = new Sudo($this->store(), $this->createStub(UserRepository::class), $this->createStub(Audit::class));
		$this->expectException(\InvalidArgumentException::class);
		$sut->request($this->actor(), $field, "/admin/", $action);
	}

	public static function invalidRequests():iterable {
		yield ["", "view"];
		yield ["field[0]", "view"];
		yield [str_repeat("a", 201), "view"];
		yield ["key", "delete"];
	}

	public function testConstruct_rejectsNonPositiveDuration():void {
		$this->expectException(\InvalidArgumentException::class);
		$sut = new Sudo($this->store(), $this->createStub(UserRepository::class), $this->createStub(Audit::class), 0);
	}

	#[DataProvider("unsafeReturns")]
	public function testReturnUri_rejectsExternalAndEscapingPaths(string $return):void {
		$sut = new Sudo($this->store(), $this->createStub(UserRepository::class), $this->createStub(Audit::class));
		$this->expectException(\InvalidArgumentException::class);
		$sut->returnUri($return);
	}

	public static function unsafeReturns():iterable {
		foreach(["https://evil.test/admin/", "//evil.test/", "/admin/../login/", "/admin/%2e%2e/login/",
			"/admin/%252e%252e/", "/admin/\\evil", "/admin/\n", "/login/"] as $return) {
			yield [$return];
		}
	}

	#[DataProvider("masks")]
	public function testObscure_preservesOnlyPermittedPrefix(string $value, string $rule, string $expected):void {
		$sut = new Sudo($this->store(), $this->createStub(UserRepository::class), $this->createStub(Audit::class));
		self::assertSame($expected, $sut->obscure($value, $rule));
	}

	public static function masks():iterable {
		yield ["DEPLOYMENT_123_abcdefghij", "key", "DEPLOYMENT_123_abcd••••••"];
		yield ["DEPLOYMENT_123_ab", "key", "DEPLOYMENT_123_a•"];
		yield ["malformed", "key", "•••••••••"];
		yield ["abc", "", "•••"];
		yield ["é😀", "unknown", "••"];
		yield ["", "key", ""];
	}
}
