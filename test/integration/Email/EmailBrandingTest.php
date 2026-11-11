<?php
namespace Authwave\Test\Integration\Email;

use Authwave\Email\{EmailBranding, EmailTemplate};
use Authwave\Model\{Application, ApplicationDeployment, ApplicationTheme};
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
		$result = (new EmailTemplate())->render("securityCode", $values + ["siteName" => "Example", "code" => "01234"]);
		self::assertStringContainsString('src="https://login.example.test:8443/asset/default-logo.svg"', $result["html"]);
		self::assertStringContainsString("prefers-color-scheme: dark", $result["html"]);
		self::assertStringNotContainsString("{{", $result["html"]);
		self::assertStringNotContainsString("prefers-color-scheme", $result["text"]);
		self::assertStringNotContainsString("Your Example security code is", $result["text"]);
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
		$html = (new EmailTemplate())->render("securityCode", $values + ["siteName" => "Example", "code" => "01234"])["html"];
		self::assertStringContainsString('bgcolor="#fcf0f5"', $html);
		self::assertStringContainsString('background-color:#272635 !important', $html);
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
			$html = (new EmailTemplate())->render("securityCode", $values + ["siteName" => "Example", "code" => "01234"])["html"];
			self::assertStringContainsString("https://login.example.test:8443/$directory/logo_dark.svg", $html);
		}
		finally {
			unlink("$directory/logo.svg");
			unlink("$directory/logo.PNG");
			unlink("$directory/logo_dark.svg");
			rmdir($directory);
		}
	}
}
