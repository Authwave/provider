<?php
namespace Authwave\Test\Model;

use Authwave\Model\ApplicationTheme;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ApplicationThemeTest extends TestCase {
	public function testOnlyKnownKeysAndHexColoursAreRendered():void {
		$theme = new ApplicationTheme("dark", [
			"primary" => "#abcdef",
			"secondary" => "#1234",
			"pageBackground" => "#12345678",
			"buttonPrimaryText" => "#fff",
			"bodyText" => "</style><script>alert(1)</script>",
			"headingText" => ["#fff"],
			"linkText" => "#fff; display:none",
			"unknown" => "#123456",
		]);
		self::assertSame(':root[data-flair-theme="bright"][data-color-scheme="dark"] { --theme-color-primary: #abcdef; --theme-color-secondary: #1234; --theme-color-page: #12345678; --theme-button-primary-text: #fff; }', $theme->toCss());
	}

	public function testInvalidJsonAndNonObjectsKeepDefaults():void {
		foreach(['{', 'null', '42', '"#fff"', '[]', '["#fff"]', '{}'] as $json) {
			self::assertSame("", ApplicationTheme::fromJson("light", $json)->toCss());
		}
	}

	public function testRejectsInvalidScheme():void {
		$this->expectException(InvalidArgumentException::class);
		new ApplicationTheme('dark"] { body { display:none }', []);
	}
}
