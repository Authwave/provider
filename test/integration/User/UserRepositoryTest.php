<?php
namespace Authwave\Test\Integration\User;

use Gt\Database\Database;
use Authwave\Model\{Application, ApplicationDeployment, ApplicationRepository};
use Authwave\User\UserRepository;
use Authwave\Email\EmailRepository;
use Authwave\Security\Audit;
use Gt\Database\Connection\Settings;
use PHPUnit\Framework\TestCase;

class UserRepositoryTest extends TestCase {
	public function testGet_emailLookupIsScopedToDeploymentAndMissingUsersReturnNull():void {
		$database = new Database(new Settings("query", "sqlite", ":memory:"));
		$database->executeSql("create table User (id text, applicationDeploymentId text, email text, hash text)");
		$database->executeSql("insert into User values ('first', 'one', 'user@example.test', null), ('second', 'two', 'user@example.test', null), ('orphan', 'missing', 'orphan@example.test', null)");

		$application = new Application("app", "App", "sender@example.test");
		$first = new ApplicationDeployment("one", $application, "One", "secret", "client.test", "/");
		$second = new ApplicationDeployment("two", $application, "Two", "secret", "client.test", "/");
		$applications = $this->createStub(ApplicationRepository::class);
		$applications->method("getDeploymentById")->willReturnMap([
			["one", $first], ["two", $second], ["missing", null],
		]);
		$sut = new UserRepository($database->queryCollection("user"), $applications,
			$this->createStub(EmailRepository::class), $this->createStub(Audit::class));

		self::assertSame("first", $sut->get($first, "user@example.test")->id);
		self::assertSame("second", $sut->get($second, "user@example.test")->id);
		self::assertSame("first", $sut->getById("first")->id);
		self::assertNull($sut->get($first, "absent@example.test"));
		self::assertNull($sut->getById(null));
		self::assertNull($sut->getById("absent"));
		self::assertNull($sut->getById("orphan"));
	}
}
