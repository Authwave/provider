<?php

namespace Authwave\Test\Email;

use Authwave\Email\EmailBranding;
use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Authwave\Model\ApplicationTheme;
use PHPUnit\Framework\TestCase;

class EmailBrandingTest extends TestCase {
	private function deployment(array $themes = [], string $appId = "email-test-no-logo"):ApplicationDeployment {
		return new ApplicationDeployment("deployment", new Application($appId, "Example", "sender@example.test", themes: $themes), "Example", "secret", "client.example.test", "/", "login.example.test:8443");
	}

	public function testPlaceholders_loginDefaultsAndAbsoluteLogoUrl():void {
		$sut = new EmailBranding();
		$values = $sut->placeholders($this->deployment());
		self::assertSame("#fafaf9", $values["pageColour"]);
		self::assertSame("#453f3a", $values["darkPageColour"]);
		self::assertSame("#59514a", $values["bodyColour"]);
		self::assertSame("#f5f5f4", $values["darkTitleColour"]);
		self::assertSame($values["panelColour"], $values["codeBackgroundColour"]);
		self::assertSame("#9e77ed", $values["codeBorderColour"]);
		self::assertSame($values["logoPath"], $values["darkLogoPath"]);
		self::assertSame("#ffffff", $values["darkLogoBackgroundColour"]);
	}

	public function testPlaceholders_emailUsesLoginThemeSurfacesAndTextInBothSchemes():void {
		$sut = new EmailBranding();
		$values = $sut->placeholders($this->deployment([
			new ApplicationTheme("light", ["primary" => "#272635", "secondary" => "#6ab3df", "pageBackground" => "#fcf0f5", "panelBackground" => "#ffffff", "panelBorder" => "#aca9c7", "bodyText" => "#453c54", "headingText" => "#453c54"], "app"),
			new ApplicationTheme("dark", ["primary" => "#6ab3df", "secondary" => "#aca9c7", "pageBackground" => "#272635", "panelBackground" => "#252734", "panelBorder" => "#4f4c6b", "bodyText" => "#f2f2f2", "headingText" => "#fcf0f5"], "app"),
		]));
		foreach([
			"pageColour" => "#fcf0f5", "panelColour" => "#ffffff", "borderColour" => "#aca9c7",
			"bodyColour" => "#453c54", "titleColour" => "#453c54", "codeColour" => "#453c54",
			"codeBackgroundColour" => "#ffffff", "codeBorderColour" => "#6ab3df",
			"darkPageColour" => "#272635", "darkPanelColour" => "#252734", "darkBorderColour" => "#4f4c6b",
			"darkBodyColour" => "#f2f2f2", "darkTitleColour" => "#fcf0f5", "darkCodeColour" => "#f2f2f2",
			"darkCodeBackgroundColour" => "#252734", "darkCodeBorderColour" => "#aca9c7",
		] as $key => $expected) {
			self::assertSame($expected, $values[$key], $key);
		}
	}

	public function testPlaceholders_controlOverridesAndIndependentDarkFallbacks():void {
		$sut = new EmailBranding();
		$defaults = $sut->placeholders($this->deployment());
		$values = $sut->placeholders($this->deployment([new ApplicationTheme("light", [
			"secondary" => "#ff0000", "pageBackground" => "#abcdef",
			"controlBackground" => "#123456", "codeInputBorder" => "#654321",
		], "app")]));
		self::assertSame("#123456", $values["codeBackgroundColour"]);
		self::assertSame("#654321", $values["codeBorderColour"]);
		foreach($defaults as $key => $value) {
			if(str_starts_with($key, "dark")) {
				self::assertSame($value, $values[$key], $key);
			}
		}
	}

	public function testPlaceholders_transparentColoursAreCompositedOverTheirActualSurface():void {
		$sut = new EmailBranding();
		$values = $sut->placeholders($this->deployment([new ApplicationTheme("dark", [
			"pageBackground" => "#000000", "panelBackground" => "#ffffff80",
			"controlBackground" => "#0000", "bodyText" => "#ffffff80",
		], "app")]));
		self::assertSame("#808080", $values["darkPanelColour"]);
		self::assertSame("#808080", $values["darkCodeBackgroundColour"]);
		self::assertSame("#c0c0c0", $values["darkBodyColour"]);
	}

	public function testPlaceholders_invalidColoursFallBackAndCustomDefaultsWork():void {
		$sut = new EmailBranding(["primary" => "#123456", "secondary" => "#abcdef"]);
		self::assertSame($sut->placeholders($this->deployment()), $sut->placeholders($this->deployment([new ApplicationTheme("light", ["primary" => 'red; background:url(evil)', "secondary" => ["invalid"]], "app")])));
		$short = $sut->placeholders($this->deployment([new ApplicationTheme("light", ["primary" => "#123f", "secondary" => "#abcd"], "app")]));
		$long = $sut->placeholders($this->deployment([new ApplicationTheme("light", ["primary" => "#112233ff", "secondary" => "#aabbccdd"], "app")]));
		self::assertSame($short, $long);
	}

	public function testPlaceholders_uploadedLogoIsUsedAndRasterPreferredForEmail():void {
		$id = "email-logo-test-" . bin2hex(random_bytes(6));
		$directory = "data/upload/$id";
		mkdir($directory, 0777, true);
		try {
			file_put_contents("$directory/logo.svg", "svg");
			file_put_contents("$directory/logo.PNG", "png");
			file_put_contents("$directory/logo_dark.svg", "svg");
			$sut = new EmailBranding();
			$values = $sut->placeholders($this->deployment(appId: $id));
			self::assertSame("/$directory/logo.PNG", $values["logoPath"]);
			self::assertSame("/$directory/logo_dark.svg", $values["darkLogoPath"]);
			self::assertSame($values["darkPanelColour"], $values["darkLogoBackgroundColour"]);

		}
		finally {
			unlink("$directory/logo.svg");
			unlink("$directory/logo.PNG");
			unlink("$directory/logo_dark.svg");
			rmdir($directory);
		}
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("brandColours")]
	public function testPlaceholders_textContrastForExtremeBrandColours(string $colour):void {
		$sut = new EmailBranding();
		$values = $sut->placeholders($this->deployment([new ApplicationTheme("light", ["primary" => $colour, "secondary" => $colour], "app")]));
		foreach([["titleColour", "panelColour"], ["codeColour", "codeBackgroundColour"], ["darkTitleColour", "darkPanelColour"], ["darkCodeColour", "darkCodeBackgroundColour"]] as [$text, $background]) {
			$a = $this->luminance($values[$text]);
			$b = $this->luminance($values[$background]);
			self::assertGreaterThanOrEqual(4.5, (max($a, $b) + 0.05) / (min($a, $b) + 0.05), "$colour: $text");
		}
	}

	private function luminance(string $colour):float {
		[$r, $g, $b] = array_map(function($hex) {
			$v = hexdec($hex) / 255;
			return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
		}, str_split(substr($colour, 1), 2));
		return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
	}

	public static function brandColours():iterable {
		foreach(["#000", "#fff", "#f00", "#0f0", "#00f", "#ff0"] as $colour) {
			yield [$colour];
		}
	}
}
