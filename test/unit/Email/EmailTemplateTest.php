<?php
namespace Authwave\Test\Email;

use Authwave\Email\{EmailTemplate, EmailTemplateNotFoundException};
use PHPUnit\Framework\TestCase;

class EmailTemplateTest extends TestCase {
	private string $directory;

	protected function setUp():void {
		$this->directory = sys_get_temp_dir() . "/authwave-email-" . bin2hex(random_bytes(6));
		mkdir($this->directory);
	}

	protected function tearDown():void {
		foreach(glob("$this->directory/*") as $path) {
			unlink($path);
		}
		rmdir($this->directory);
	}

	public function testHtmlIsNotParsedAsMarkdownAndValuesAreEscaped():void {
		file_put_contents("$this->directory/example.html", '<html><head><title>{{name}} &amp; code {{code}}</title><style>.secret { color: red; }</style></head><body><p>{{name}}</p><p>**literal**</p><strong>{{code}}</strong></body></html>');
		$result = (new EmailTemplate($this->directory))->render("example", ["name" => 'R&D <img src=x>', "code" => "01234"]);
		self::assertSame('R&D <img src=x> & code 01234', $result["subject"]);
		self::assertStringContainsString('R&amp;D &lt;img src=x&gt;', $result["html"]);
		self::assertStringContainsString('**literal**', $result["html"]);
		self::assertStringNotContainsString('<img src=x>', $result["html"]);
		self::assertSame("R&D <img src=x>\n**literal**\n01234", $result["text"]);
	}

	public function testMarkdownStillRendersWithFirstHeadingAsSubject():void {
		file_put_contents("$this->directory/example.md", "# Code for {{name}}\n\nHello **{{name}}**.\n\nYour code is {{code}}.");
		$result = (new EmailTemplate($this->directory))->render("example.md", ["name" => "R&D", "code" => "01234"]);
		self::assertSame("Code for R&D", $result["subject"]);
		self::assertStringContainsString("<strong>R&amp;D</strong>", $result["html"]);
		self::assertStringContainsString("Hello R&D.", $result["text"]);
		self::assertStringNotContainsString("<", $result["text"]);
	}

	public function testHtmlTakesPrecedenceUnlessExtensionIsExplicit():void {
		file_put_contents("$this->directory/example.html", "<title>HTML</title><p>HTML</p>");
		file_put_contents("$this->directory/example.md", "# Markdown\n\nMarkdown");
		$renderer = new EmailTemplate($this->directory);
		self::assertSame("HTML", $renderer->render("example")["subject"]);
		self::assertSame("HTML", $renderer->render("example.html")["subject"]);
		self::assertSame("Markdown", $renderer->render("example.md")["subject"]);
	}

	public function testMissingTemplateIsRejected():void {
		$this->expectException(EmailTemplateNotFoundException::class);
		(new EmailTemplate($this->directory))->render("missing");
	}

	public function testHtmlRequiresSubjectTitle():void {
		file_put_contents("$this->directory/example.html", "<p>Missing title</p>");
		$this->expectException(\UnexpectedValueException::class);
		(new EmailTemplate($this->directory))->render("example");
	}
}
