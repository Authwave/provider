<?php
namespace Authwave\Test\Email;

use Authwave\Model\EmailSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmailSettingsTest extends TestCase {
	#[DataProvider("replyToAddresses")]
	public function testGetReplyTo_usesConfiguredAddressOrClientHostname(string $host,
		?string $configured, ?string $expected):void {
		$sut = new EmailSettings("localhost", 1025, "", "", $configured);
		self::assertSame($expected, $sut->getReplyTo($host));
	}

	/** @return array<string, array{string, ?string, ?string}> */
	public static function replyToAddresses():array {
		return [
			"explicit" => ["login.example.com", "help@example.org", "help@example.org"],
			"trim explicit" => ["login.example.com", " help@example.org ", "help@example.org"],
			"default" => ["https://client.example.com:8443/", null, "support@client.example.com"],
			"blank default" => ["login.example.com", "", "support@login.example.com"],
			"country suffix" => ["login.example.co.uk", null, "support@login.example.co.uk"],
			"nested hostname" => ["login.app.example.com", null, "support@login.app.example.com"],
			"port and case" => ["LOGIN.Example.COM.:8443", null, "support@login.example.com"],
			"root domain" => ["example.com", null, "support@example.com"],
			"localhost" => ["localhost:8080", null, null],
			"IPv4" => ["127.0.0.1:8080", null, null],
			"IPv6" => ["[::1]:8080", null, null],
			"missing hostname" => ["", null, null],
		];
	}
}
