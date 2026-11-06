<?php

namespace Authwave\Test\Admin\Onboarding;

use Authwave\Admin\Onboarding\Onboarding;
use Gt\Http\Uri;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OnboardingTest extends TestCase {
	#[DataProvider("routes")]
	public function testRedirectFor_emptyAccountNavigation(string $path, bool $allowed):void {
		$sut = new Onboarding(0);
		$redirect = $sut->redirectFor(new Uri("https://provider.test$path"));
		self::assertSame($allowed ? null : "/admin/applications/#first-application", $redirect === null
			? null : (string)$redirect);
	}

	public static function routes():iterable {
		foreach([
			"/admin/", "/admin", "/admin/users/", "/admin/security/", "/admin/emails/",
			"/admin/customisation/", "/admin/organisation/", "/admin/applications-other/",
			"/admin/billing-other/", "/admin/integrations-other/",
		] as $path) {
			yield [$path, false];
		}
		foreach([
			"/admin/applications", "/admin/applications/", "/admin/applications/new/",
			"/admin/billing/", "/admin/billing/?application=missing",
			"/admin/integrations/", "/admin/integrations/help/", "/integrations/help/",
			"/admin/logout/",
		] as $path) {
			yield [$path, true];
		}
	}

	#[DataProvider("existingAccounts")]
	public function testRedirectFor_establishedAccountIsNotRedirected(int $count, string $path):void {
		$sut = new Onboarding($count);

		self::assertNull($sut->redirectFor(new Uri($path)));
	}

	public static function existingAccounts():iterable {
		yield [1, "/admin/"];
		yield [PHP_INT_MAX, "/admin/users/"];
	}
	public function testConstruct_negativeCountIsRejected():void {
		$this->expectException(InvalidArgumentException::class);
		$sut = new Onboarding(-1);
	}

	public function testSteps_newAccountHasOneOfSevenCompleteAndApplicationStepOpen():void {
		$sut = new Onboarding(0);
		$steps = iterator_to_array($sut->steps());
		self::assertTrue($sut->needsApplication());
		self::assertSame(1, $sut->completedCount());
		self::assertCount(7, $steps);
		self::assertTrue($steps[0]->complete);
		self::assertSame("circle-check", $steps[0]->icon);
		self::assertFalse($steps[0]->current);
		self::assertFalse($steps[1]->complete);
		self::assertSame("shield", $steps[1]->icon);
		self::assertTrue($steps[1]->current);
		self::assertSame("Create your first application", $steps[1]->title);
		foreach(array_slice($steps, 2) as $step) {
			self::assertFalse($step->complete);
			self::assertFalse($step->current);
		}
	}

	public function testSteps_firstApplicationAdvancesOnlyTheKnownSetupStep():void {
		$sut = new Onboarding(1);
		$steps = iterator_to_array($sut->steps());
		self::assertFalse($sut->needsApplication());
		self::assertSame(2, $sut->completedCount());
		self::assertTrue($steps[1]->complete);
		self::assertTrue($steps[2]->current);
		self::assertSame(range(1, 7), array_map(fn($step) => $step->number, $steps));
	}
	public function testSteps_verifiedDomainCompletesTheThirdStep():void {
		$sut = new Onboarding(1, true);

		$steps = iterator_to_array($sut->steps());

		self::assertSame(3, $sut->completedCount());
		self::assertTrue($steps[2]->complete);
		self::assertTrue($steps[3]->current);
	}

	#[DataProvider("clientProgress")]
	public function testCompletedCount_tracksClientMilestones(bool $connected, bool $signedIn, int $expected):void {
		$sut = new Onboarding(1, true, $connected, $signedIn);

		self::assertSame($expected, $sut->completedCount());
		$steps = iterator_to_array($sut->steps());
		self::assertSame($expected >= 4, $steps[3]->complete);
		self::assertSame($expected >= 5, $steps[4]->complete);
	}

	public static function clientProgress():iterable {
		yield [false, false, 3];
		yield [false, true, 3];
		yield [true, false, 4];
		yield [true, true, 5];
	}

}
