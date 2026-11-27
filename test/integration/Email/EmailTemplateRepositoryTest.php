<?php
namespace Authwave\Test\Integration\Email;

use Authwave\Email\{EmailTemplateRepository, EmailRepository, EmailBranding};
use Authwave\Model\{ApplicationRepository, Application, ApplicationDeployment, User, EmailSettings};
use Authwave\Security\{Audit, DomainVerification};
use DateTimeImmutable;
use GT\Database\Connection\Settings;
use GT\Database\Database;
use GT\Http\ResponseStatusException\ClientError\HttpNotFound;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EmailTemplateRepositoryTest extends TestCase {
	private Database $database;
	private ApplicationRepository $applications;
	private EmailTemplateRepository $templates;
	private User $actor;

	protected function setUp():void {
		$this->database = new Database(new Settings("query", "sqlite", ":memory:"));
		foreach(explode(";", file_get_contents("test/behat/database/schema/001-schema.sql")) as $sql) {
			if(trim($sql) !== "") $this->database->executeSql($sql);
		}
		$this->database->executeSql("insert into Application (id,name) values ('app','Example & Co'),('other','Other')");
		$this->database->executeSql("insert into ApplicationDeployment (id,applicationId,title,secret,providerHost,clientHost,clientLoginPath) values ('dev','app','Development','s','dev.test','https://client.test','/login/'),('prod','app','Production','s','prod.test','https://prod-client.test','/login/'),('private','other','Private','s','private.test','https://private.test','/')");
		$this->database->executeSql("insert into UserAdmin values ('admin','app')");
		$audit = new Audit($this->database->queryCollection("audit"));
		$this->applications = new ApplicationRepository($this->database, $audit, new DomainVerification());
		$this->templates = new EmailTemplateRepository($this->database, $this->applications, $audit);
		$this->actor = new User("admin", new ApplicationDeployment("private", new Application("other", "Other", null),
			"Private", "s", "https://private.test", "/"), "admin@example.test");
	}

	public function testDefaultsOverridesInheritanceAndReset():void {
		foreach(EmailTemplateRepository::OPTIONS as $name => $label) {
			$default = $this->templates->get($this->actor, "app", $name);
			self::assertFalse($default["custom"]);
			self::assertStringContainsString("<!doctype html>", $default["html"]);
		}
		$html = '<title>Old title</title><p>Custom code: {{code}}</p>';
		$this->templates->save($this->actor, "app", "securityCode", "", "App {{siteName}}", $html);
		$inherited = $this->templates->get($this->actor, "app", "securityCode", "prod");
		self::assertFalse($inherited["custom"]);
		self::assertSame("App {{siteName}}", $inherited["subject"]);
		self::assertStringContainsString('<title>App {{siteName}}</title>', $inherited["html"]);
		$this->templates->save($this->actor, "app", "securityCode", "dev", "Dev", $html);
		self::assertTrue($this->templates->get($this->actor, "app", "securityCode", "dev")["custom"]);
		self::assertSame("Dev", $this->templates->get($this->actor, "app", "securityCode", "dev")["subject"]);
		self::assertSame("App {{siteName}}", $this->templates->get($this->actor, "app", "securityCode", "prod")["subject"]);
		$this->templates->reset($this->actor, "app", "securityCode", "dev");
		self::assertSame("App {{siteName}}", $this->templates->get($this->actor, "app", "securityCode", "dev")["subject"]);
		$this->templates->reset($this->actor, "app", "securityCode", "");
		self::assertFalse($this->templates->get($this->actor, "app", "securityCode")["custom"]);
	}

	public function testUnchangedTemplatesUseTheFilesAndTheirTitleAsSubject():void {
		foreach(EmailTemplateRepository::OPTIONS as $name => $label) {
			$source = file_get_contents("data/email/$name.html");
			preg_match('/<title>(.*?)<\/title>/s', $source, $matches);
			$default = $this->templates->get($this->actor, "app", $name);
			self::assertSame(html_entity_decode($matches[1]), $default["subject"]);
			$this->templates->save($this->actor, "app", $name, "", $default["subject"],
				str_replace("\n", "\r\n", $default["html"]));
			self::assertFalse($this->templates->get($this->actor, "app", $name)["custom"]);
		}
		$rows = $this->database->executeSql("select count(*) as total from EmailTemplate")->fetch();
		self::assertSame(0, $rows->getInt("total"));
	}

	public function testResetRestoresFileContentForOutgoingMail():void {
		$deployment = $this->applications->getDeploymentById("dev");
		$values = ["siteName" => "Example & Co", "code" => "123456"]
			+ (new EmailBranding())->placeholders($deployment);
		$this->templates->save($this->actor, "app", "securityCode", "", "Custom subject",
			'<title>Custom subject</title><p>Custom code {{code}}</p>');
		self::assertSame("Custom subject", $this->templates->render($deployment, "securityCode", $values)["subject"]);
		$this->templates->reset($this->actor, "app", "securityCode", "");
		$rendered = $this->templates->render($deployment, "securityCode", $values);
		self::assertSame("Your Example & Co security code is 123456", $rendered["subject"]);
		self::assertStringContainsString("Your sign-in code", $rendered["html"]);
		self::assertStringNotContainsString("Custom code", $rendered["html"]);
	}

	public function testPreviewEscapesValuesAndDoesNotPersistDraft():void {
		$default = $this->templates->get($this->actor, "app", "securityCode");
		$preview = $this->templates->preview($this->actor, "app", "securityCode", "dev",
			"Preview {{siteName}}", $default["html"]);
		self::assertSame("Preview Example & Co", $preview["subject"]);
		self::assertStringContainsString("Example &amp; Co", $preview["html"]);
		self::assertStringContainsString("123456", $preview["html"]);
		self::assertStringNotContainsString("{{", $preview["html"]);
		self::assertStringNotContainsString("prefers-color-scheme", $preview["text"]);
		self::assertFalse($this->templates->get($this->actor, "app", "securityCode")["custom"]);
	}

	public function testQueuedMailUsesTheDeploymentTemplateAndRendersItsPlaceholders():void {
		$html = '<title>Old title</title><p>Custom code: {{code}}</p>';
		$this->templates->save($this->actor, "app", "securityCode", "", "Application", $html);
		$this->templates->save($this->actor, "app", "securityCode", "dev", "Dev {{siteName}}", $html);
		$deployment = $this->applications->getDeploymentById("dev");
		$emails = new EmailRepository($this->database->queryCollection("email"),
			new EmailSettings("localhost", 1025, "", ""), new Audit($this->database->queryCollection("audit")),
			templates: $this->templates);
		$id = $emails->schedule($this->actor, $deployment, "person@example.test", "securityCode.html",
			["siteName" => "Example & Co", "code" => "098765"] + (new EmailBranding())->placeholders($deployment),
			when: new DateTimeImmutable("+1 day"));
		$row = $this->database->executeSql("select subject,htmlContent,textContent from Email where id=?", [$id])->fetch();
		self::assertSame("Dev Example & Co", $row->getString("subject"));
		self::assertStringContainsString("Custom code: 098765", $row->getString("htmlContent"));
		self::assertSame("Custom code: 098765", $row->getString("textContent"));
	}

	public function testAnotherAccountCannotReadOrEditTemplates():void {
		$this->expectException(HttpNotFound::class);
		$this->templates->get($this->actor, "other", "securityCode");
	}

	public function testAnotherApplicationsDeploymentCannotBeEdited():void {
		$this->expectException(InvalidArgumentException::class);
		$this->templates->save($this->actor, "app", "passwordChanged", "private", "Forged", '<title>Forged</title>');
	}

	public function testInvalidDraftDoesNotReplaceTheSavedTemplate():void {
		$html = '<title>Saved</title><p>{{code}}</p>';
		$this->templates->save($this->actor, "app", "securityCode", "", "Saved", $html);
		foreach([
			["securityCode", "Invalid", '<title>No code</title>'],
			["securityCode", "Invalid", '<title>{{code}}</title><p>No code in body</p>'],
			["securityCode", "Code {{code}}", '<title>{{code}}</title><p>No code in body</p>'],
			["securityCode", "Invalid", '<p>{{code}}</p>'],
			["securityCode", "", $html],
			["securityCode", "Injected\r\nBcc: person@example.test", $html],
			["../securityCode", "Invalid", $html],
		] as [$name, $subject, $content]) {
			try {
				$this->templates->save($this->actor, "app", $name, "", $subject, $content);
				self::fail("Invalid templates must not be stored.");
			}
			catch(InvalidArgumentException) {
				self::assertSame("Saved", $this->templates->get($this->actor, "app", "securityCode")["subject"]);
			}
		}
	}

	public function testAuditFailureRollsBackEditsAndResets():void {
		$this->templates->save($this->actor, "app", "passwordChanged", "", "Saved", '<title>Saved</title>');
		$this->database->executeSql("create trigger refuse_template_audit before insert on Audit begin select raise(abort, 'audit failed'); end");
		foreach(["save", "reset"] as $operation) {
			try {
				if($operation === "save") {
					$this->templates->save($this->actor, "app", "passwordChanged", "", "Changed", '<title>Changed</title>');
				}
				else {
					$this->templates->reset($this->actor, "app", "passwordChanged", "");
				}
				self::fail("Unaudited template changes must not be committed.");
			}
			catch(\Throwable $error) {
				self::assertStringContainsString("audit failed", $error->getMessage());
				self::assertSame("Saved", $this->templates->get($this->actor, "app", "passwordChanged")["subject"]);
			}
		}
	}
}
