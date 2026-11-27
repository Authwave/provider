<?php
namespace Authwave\Test\Admin\Security;

use Authwave\Admin\Security\SecurityScore;
use Authwave\Admin\User\ActiveSession;
use Authwave\Model\{Application, ApplicationDeployment, ApplicationSessionPolicy, UserSession};
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SecurityScoreTest extends TestCase {
	#[DataProvider("configurations")]
	public function testConstruct_gradesKnownControls(?ApplicationSessionPolicy $policy,
		?ApplicationDeployment $production, array $sessions, string $expected):void {
		$sut = new SecurityScore($policy, $production, $sessions, "/admin/applications/app/");

		self::assertSame($expected, $sut->grade);
	}

	public function testConstruct_explainsTheLimitsAndLinksToSettings():void {
		$sut = new SecurityScore(null, null, [], "/admin/applications/app/");

		self::assertSame("Save a session policy to require managed clients.", $sut->checks[0]->description);
		self::assertSame("#sessions", $sut->checks[0]->url);
		self::assertSame("/admin/applications/app/", $sut->checks[1]->url);
		self::assertSame("No active sessions to assess.", $sut->checks[2]->description);
		self::assertSame([0, 0, 0], array_column($sut->checks, "adjustment"));
	}

	#[DataProvider("productionHosts")]
	public function testConstruct_awardsProductionClientCredit(string $host, bool $markedProduction,
		int $expectedAdjustment):void {
		$application = new Application("app", "App", null,
			productionApplicationDeploymentId: $markedProduction ? "prod" : null);
		$deployment = new ApplicationDeployment("prod", $application, "Production", "secret", $host, "/");
		$sut = new SecurityScore(null, $deployment, [], "/admin/applications/app/");

		$checks = array_filter($sut->checks,
			fn($check) => $check->description === "A production client is created, using HTTPS.");
		self::assertCount($expectedAdjustment === 1 ? 1 : 0, $checks);
		self::assertSame($expectedAdjustment, array_sum(array_column($sut->checks, "adjustment")));
	}

	public static function productionHosts():iterable {
		yield "explicit HTTPS" => ["https://login.example.com", true, 1];
		yield "no scheme" => ["login.example.com", true, 1];
		yield "explicit HTTP" => ["http://login.example.com", true, -1];
		yield "uppercase HTTP" => ["HTTP://login.example.com", true, -1];
		yield "not marked production" => ["login.example.com", false, 0];
	}

	public static function configurations():iterable {
		$application = new Application("app", "App", null, productionApplicationDeploymentId: "prod");
		$https = new ApplicationDeployment("prod", $application, "Production", "secret", "https://client.test", "/", domainVerified: true);
		$http = new ApplicationDeployment("prod", $application, "Production", "secret", "http://client.test", "/", domainVerified: true);
		$unverified = new ApplicationDeployment("prod", $application, "Production", "secret", "https://client.test", "/");
		$implicitHttps = new ApplicationDeployment("prod", $application, "Production", "secret", "client.test", "/", domainVerified: true);
		$unverifiedHttp = new ApplicationDeployment("prod", $application, "Production", "secret", "http://client.test", "/");
		$development = new ApplicationDeployment("dev", $application, "Development", "secret", "https://dev.test", "/", domainVerified: true);
		$policy = new ApplicationSessionPolicy("app");
		$longMaximum = new ApplicationSessionPolicy("app", 60, 15);
		$longIdle = new ApplicationSessionPolicy("app", 30, 30);
		$time = new DateTimeImmutable("2026-11-25 12:00:00");
		$legacy = new ActiveSession(new UserSession("legacy", "user", $time, $time->modify("+1 hour")),
			"user@example.test", "dev", "Development");
		$managed = new ActiveSession(new UserSession("managed", "user", $time, $time->modify("+1 hour"),
			lastActivityAt: $time, idleTimeoutMinutes: 15, managed: true), "user@example.test", "prod", "Production");

		yield "unknown controls stay C" => [null, null, [], "C"];
		yield "managed traffic alone earns no bonus" => [null, null, [$managed], "C"];
		yield "policy raises one grade" => [$policy, null, [], "B"];
		yield "verified production HTTPS raises two grades" => [null, $https, [], "A"];
		yield "implicit HTTPS follows the application's URL behaviour" => [null, $implicitHttps, [], "A"];
		yield "both improvements reach A" => [$policy, $https, [$managed], "A"];
		yield "unverified production HTTPS earns credit" => [null, $unverified, [], "B"];
		yield "production HTTP lowers one grade" => [null, $unverifiedHttp, [], "D"];
		yield "verified HTTP production earns domain credit separately" => [null, $http, [], "C"];
		yield "verified development earns no domain credit" => [null, $development, [], "C"];
		yield "older clients lower one grade" => [null, null, [$legacy], "D"];
		yield "multiple older clients count as one finding" => [null, null, [$legacy, $legacy], "D"];
		yield "long maximum lowers one grade" => [$longMaximum, null, [], "D"];
		yield "long idle lowers one grade" => [$longIdle, null, [], "D"];
		yield "two weaknesses reach E" => [null, $unverifiedHttp, [$legacy], "E"];
		yield "three weaknesses reach F" => [$longMaximum, $unverifiedHttp, [$legacy], "F"];
		yield "findings offset improvements" => [$policy, $https, [$legacy], "A"];
	}
}
