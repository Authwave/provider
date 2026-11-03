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

		self::assertSame($expected, [$sut->name, $sut->host, $sut->clientUrl, $sut->clientLoginPath]);
	}

	public static function validValues():iterable {
		yield "localhost" => [" Development ", "LOCALHOST:9105", "http://localhost:8080/", "/login/",
			["Development", "localhost:9105", "http://localhost:8080", "/login/"]];
		yield "IPv6 and Unicode" => [str_repeat("馬", 32), "[::1]:9105", "https://[::1]:8080", "/",
			[str_repeat("馬", 32), "[::1]:9105", "https://[::1]:8080", "/"]];
		yield "HTTPS" => ["Production", "LOGIN.EXAMPLE.TEST", "HTTPS://WWW.EXAMPLE.TEST/", "/",
			["Production", "login.example.test", "https://www.example.test", "/"]];
	}
	#[DataProvider("invalid")]
	public function testConstruct_missingBadAndOversizedFieldsAreRejected(?string $name, ?string $host,
		?string $client, ?string $path):void {
		$this->expectException(\InvalidArgumentException::class);
		$sut = new DeploymentData($name, $host, $client, $path);
	}

	public static function invalid():iterable {
		$valid = ["Development", "login.example.test", "https://www.example.test", "/login/"];
		foreach([
			0 => [null, "", " ", str_repeat("a", 33), "bad\nname", "\xFF"],
			1 => [null, "", "https://login.test", "login.test/path", "user@host.test", "bad host", "host:99999", "host.test?query", str_repeat("a", 257)],
			2 => [null, "", "example.test", "javascript:alert(1)", "ftp://host.test", "https://user:pass@host.test", "https://host.test/path", "https://host.test?q=1", "https://host.test/#x"],
			3 => [null, "", "login", "//evil.test", "/\\evil.test", "/bad%0dpath", "/bad?query", "/bad#fragment", "/bad%xy", str_repeat("/", 257)],
		] as $index => $values) {
			foreach($values as $value) {
				$row = $valid;
				$row[$index] = $value;
				yield $row;
			}
		}
	}
}
