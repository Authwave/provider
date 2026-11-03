<?php

namespace Authwave\Test\Admin\Application;

use Authwave\Model\ApplicationRepository;
use Authwave\Admin\Application\ApplicationCatalogue;
use Authwave\Admin\Application\ManagedApplication;
use Authwave\Model\User;
use Gt\Session\Session;
use Gt\Session\SessionStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApplicationCatalogueTest extends TestCase {
	public function testConstruct_selectionChangeIsAuditedBeforeUpdatingTheSession():void {
		$store = $this->createStub(ApplicationRepository::class);
		$store->method("allFor")->willReturn([new ManagedApplication("next", "Next")]);
		$session = new SessionStore("selection", $this->createStub(Session::class));
		$session->set("selected", "previous");
		$audit = $this->createMock(\Authwave\Security\Audit::class);
		$audit->expects(self::once())->method("recordChange")->with(
			\Authwave\Security\Action::APPLICATION_SELECTED,
			self::callback(function(object $before)use($session):bool {
				self::assertSame("previous", $session->getString("selected"));
				return $before->applicationId === "previous";
			}), (object)["applicationId" => "next"]);
		$sut = new ApplicationCatalogue($store, $this->createStub(User::class), $session, $audit, "next");
		self::assertSame("next", $session->getString("selected"));
	}

	#[DataProvider("selections")]
	public function testSelected_selectionIsAlwaysRestrictedToOwnedApplications(
		?string $requested, ?string $saved, string $expected,
	):void {
		$store = $this->createStub(ApplicationRepository::class);
		$store->method("allFor")->willReturn([
			new ManagedApplication("first", "First"), new ManagedApplication("second", "Second"),
		]);
		$session = new SessionStore("selection", $this->createStub(Session::class));
		$session->set("selected", $saved);
		$sut = new ApplicationCatalogue($store, $this->createStub(User::class), $session, $this->createStub(\Authwave\Security\Audit::class), $requested);
		self::assertSame($expected, $sut->selected()->identifier);
		self::assertSame($expected, $session->getString("selected"));
		$options = iterator_to_array($sut->options());
		self::assertCount(2, $sut->all());
		self::assertCount(1, array_filter($options, fn($option) => $option->selected));
		self::assertSame("First", $options[0]->label);
	}

	public static function selections():iterable {
		yield [null, null, "first"];
		yield ["second", null, "second"];
		yield [null, "second", "second"];
		yield ["other-user-app", "second", "first"];
		yield [null, "revoked-access", "first"];
		yield ["", null, "first"];
	}

	public function testAll_emptyCatalogueClearsStaleSelectionAndProvidesNoFakeApplications():void {
		$store = $this->createStub(ApplicationRepository::class);
		$store->method("allFor")->willReturn([]);
		$session = new SessionStore("selection", $this->createStub(Session::class));
		$session->set("selected", "old");
		$sut = new ApplicationCatalogue($store, $this->createStub(User::class), $session, $this->createStub(\Authwave\Security\Audit::class));
		self::assertSame([], $sut->all());
		self::assertNull($sut->selected());
		self::assertNull($session->getString("selected"));
		self::assertSame([], iterator_to_array($sut->options()));
	}
}
