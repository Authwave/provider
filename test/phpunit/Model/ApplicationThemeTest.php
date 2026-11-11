<?php

namespace Authwave\Test\Model;

use Authwave\Model\ApplicationTheme;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ApplicationThemeTest extends TestCase {
	public function testToCss_onlyKnownKeysAndHexColoursAreRendered():void {
		$sut = new ApplicationTheme("dark", [
			"primary" => "#abcdef",
			"secondary" => "#1234",
			"pageBackground" => "#12345678",
			"buttonPrimaryText" => "#fff",
			"bodyText" => "</style><script>alert(1)</script>",
			"headingText" => ["#fff"],
			"linkText" => "#fff; display:none",
			"unknown" => "#123456",
		], "app");
		self::assertSame(':root[data-flair-theme="bright"][data-color-scheme="dark"] { --theme-color-primary: #abcdef; --theme-color-secondary: #1234; --theme-color-page: #12345678; --theme-button-primary-text: #fff; }', $sut->toCss());
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("invalidJson")]
	public function testFromJson_invalidJsonAndNonObjectsKeepDefaults(string $json):void {
		$sut = ApplicationTheme::fromJson("light", $json, "app");

		self::assertSame("", $sut->toCss());
	}

	public static function invalidJson():iterable {
		foreach(['{', 'null', '42', '"#fff"', '[]', '["#fff"]', '{}'] as $json) {
			yield [$json];
		}
	}
	public function testConstruct_rejectsInvalidScheme():void {
		$this->expectException(InvalidArgumentException::class);
		$sut = new ApplicationTheme('dark"] { body { display:none }', [], "app");
	}
}
