<?php
namespace Authwave\Test\Email;

use Authwave\Model\EmailSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmailSettingsTest extends TestCase {
	#[DataProvider("replyToAddresses")]
	public function testGetReplyTo_usesConfiguredAddressOrProviderParent(string $host,
		?string $configured, ?string $expected):void {
		$sut = new EmailSettings("localhost", 1025, "", "", $configured);
		self::assertSame($expected, $sut->getReplyTo($host));
	}

	/** @return array<string, array{string, ?string, ?string}> */
	public static function replyToAddresses():array {
		return [
			"explicit" => ["login.example.com", "help@example.org", "help@example.org"],
			"trim explicit" => ["login.example.com", " help@example.org ", "help@example.org"],
			"default" => ["login.example.com", null, "support@example.com"],
			"blank default" => ["login.example.com", "", "support@example.com"],
			"country suffix" => ["login.example.co.uk", null, "support@example.co.uk"],
			"nested parent" => ["login.app.example.com", null, "support@app.example.com"],
			"port and case" => ["LOGIN.Example.COM.:8443", null, "support@example.com"],
			"root domain" => ["example.com", null, "support@example.com"],
			"localhost" => ["localhost:8080", null, null],
			"IPv4" => ["127.0.0.1:8080", null, null],
			"IPv6" => ["[::1]:8080", null, null],
			"missing hostname" => ["", null, null],
		];
	}
}
