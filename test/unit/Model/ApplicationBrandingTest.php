<?php
namespace Authwave\Test\Model;

use Authwave\Model\ApplicationRepository;
use Authwave\Security\{AnonUser, Audit};
use Gt\Database\Query\QueryCollection;
use Gt\Database\Result\Row;
use PDO;
use PHPUnit\Framework\TestCase;

class ApplicationBrandingTest extends TestCase {
	/** @dataProvider data_colours */
	public function testMigrationAndDeploymentQueriesLoadColour(?string $lightColours, ?string $darkColours):void {
		$root = dirname(__DIR__, 3);
		$pdo = new PDO("sqlite::memory:");
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec("create table application (id text primary key, name text, emailSendFrom text, emailSettings text, productionApplicationDeploymentId text)");
		$pdo->exec("create table application_deployment (id text, applicationId text, title text, secret text, providerHost text, clientHost text, clientLoginPath text)");
		$pdo->exec("insert into application values ('app', 'Application', 'test@example.test', null, 'deployment')");
		$pdo->exec(file_get_contents("$root/query/_migration/014-application-theme.sql"));
		foreach(["light" => $lightColours, "dark" => $darkColours] as $scheme => $colours) {
			if($colours !== null) {
				$pdo->prepare("insert into application_theme values ('app', ?, ?)")->execute([$scheme, $colours]);
			}
		}
		$pdo->exec("insert into application values ('other', 'Other app', 'other@example.test', null, null)");
		$pdo->exec("insert into application_theme values ('other', 'dark', '{\"primary\":\"#ffffff\"}')");
		$pdo->exec("insert into application_deployment values ('deployment', 'app', 'Application', 'secret', 'provider.example.test', 'client.example.test', '/login')");

		$db = $this->createMock(QueryCollection::class);
		$db->method("fetch")->willReturnCallback(static function(string $query, string $value) use($pdo, $root):Row {
			$statement = $pdo->prepare(file_get_contents("$root/query/application/$query.sql"));
			$statement->execute([$value]);
			return new Row($statement->fetch(PDO::FETCH_ASSOC));
		});
		$repository = new ApplicationRepository($db, $this->createMock(Audit::class), $this->createMock(AnonUser::class));
		self::assertSame("provider.example.test", $repository->getDeploymentById("deployment")->providerHost);
		self::assertSame("provider.example.test", $repository->getDeploymentByProviderHost("provider.example.test")->providerHost);
		self::assertSame("provider.example.test", $repository->getDeploymentByClientHost("client.example.test")->providerHost);
		foreach([
			$repository->getById("app"),
			$repository->getDeploymentById("deployment")->application,
			$repository->getDeploymentByProviderHost("provider.example.test")->application,
			$repository->getDeploymentByClientHost("client.example.test")->application,
		] as $application) {
			$actual = [];
			foreach($application->themes as $theme) {
				$actual[$theme->colourScheme] = $theme->colours;
			}
			$expected = [];
			foreach(["light" => $lightColours, "dark" => $darkColours] as $scheme => $colours) {
				if($colours !== null) {
					$expected[$scheme] = json_decode($colours, true);
				}
			}
			self::assertSame($expected, $actual);
		}
	}

	public function data_colours():array {
		return [
			[null, null],
			['{"primary":"#123456"}', null],
			[null, '{"primary":"#abcdef"}'],
			['{"primary":"#123456","panelBackground":"#fff"}', '{"primary":"#abcdef","panelBackground":"#222"}'],
			['{}', '{}'],
		];
	}
}
