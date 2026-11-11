<?php

namespace Authwave\Test\UI;

use Authwave\UI\DeletionPrompt;
use PHPUnit\Framework\TestCase;

class DeletionPromptTest extends TestCase {
	public function testIsConfirmed_promptUsesTheSavedNameAndConfirmationMatchesOnlyItsTarget():void {
		$sut = new DeletionPrompt("deployment", '<strong>Café</strong>', '/admin/applications/app/');
		self::assertSame('Confirm deleting <strong>Café</strong>', $sut->message);
		self::assertSame('/admin/applications/app/?confirm-delete=deployment#delete-confirmation', $sut->confirmationUrl);
		self::assertTrue($sut->isConfirmed("deployment"));
		foreach([null, "", true, "other", ["deployment"]] as $value) {
			self::assertFalse($sut->isConfirmed($value));
		}
	}
}
