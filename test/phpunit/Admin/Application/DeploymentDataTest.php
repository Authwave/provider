<?php

namespace Authwave\Test\Admin\Application;

use Authwave\Admin\Application\DeploymentData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeploymentDataTest extends TestCase {
	#[DataProvider("validValues")]
	public function testConstruct_validLocalAndRemoteValuesAreNormalised(
		string $name, string $host, string $client, string $path, array $expected,
	):void {
		$sut = new DeploymentData($name, $host, $client, $path);

		self::assertSame($expected, [$sut->title, $sut->providerHost, $sut->clientHost, $sut->clientLoginPath]);
	}

	public static function validValues():iterable {
		yield "localhost" => [" Development ", "LOCALHOST:9105", "http://localhost:8080/", "/login/",
			["Development", "localhost:9105", "http://localhost:8080", "/login/"]];
		yield "Localhost and Unicode" => [str_repeat("馬", 32), "localhost:9105", "https://localhost:8080", "/",
			[str_repeat("馬", 32), "localhost:9105", "https://localhost:8080", "/"]];
		yield "HTTPS" => ["Production", "LOGIN.EXAMPLE.TEST", "HTTPS://WWW.EXAMPLE.TEST/", "/",
			["Production", "login.example.test", "https://www.example.test", "/"]];
	}
	#[DataProvider("invalidEmailSettings")]
	public function testConstruct_rejectsInvalidEmailSettings(string $prefix, string $replyTo):void {
		$this->expectException(\InvalidArgumentException::class);
		new DeploymentData("Production", "login.example.com", "https://example.com", "/", $prefix, $replyTo);
	}

	public static function invalidEmailSettings():iterable {
		yield ["security@example.com", ""];
		yield ["hello world", ""];
		yield [".security", ""];
		yield [str_repeat("x", 65), ""];
		yield ["security", "invalid"];
		yield ["security", "team@example.com\r\nBcc: victim@example.com"];
	}

	public function testConstruct_normalisesEmailSettingsAndPreservesZeroPrefix():void {
		$sut = new DeploymentData("Production", "login.example.com", "https://example.com", "/",
			" 0 ", " team@example.com ");
		self::assertSame("0", $sut->emailPrefix);
		self::assertSame("team@example.com", $sut->emailReplyTo);
	}

}
