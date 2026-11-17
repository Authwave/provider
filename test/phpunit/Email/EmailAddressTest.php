<?php
namespace Authwave\Test\Email;

use Authwave\Email\EmailAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmailAddressTest extends TestCase {
	#[DataProvider("addresses")]
	public function testNormalize(string $input, string $expected):void {
		self::assertSame($expected, EmailAddress::normalize($input));
	}

	#[DataProvider("addresses")]
	public function testStringCoercionNormalizesAddress(string $input, string $expected):void {
		$acceptString = static fn(string $address):string => $address;

		self::assertSame($expected, $acceptString(new EmailAddress($input)));
	}

	public static function addresses():array {
		return [
			["test.user.@example.com", "test.user@example.com"],
			[" ..test.user...@example.com ", "test.user@example.com"],
			["test.user+tag@example.com", "test.user+tag@example.com"],
			['".test.user."@example.com', '".test.user."@example.com'],
			["missing-at", "missing-at"],
		];
	}
}
