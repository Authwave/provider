<?php
namespace Authwave\Test\Crypto;

use Authwave\Crypto\ProviderUri;
use Gt\Http\Uri;
use PHPUnit\Framework\TestCase;

class ProviderUriTest extends TestCase {
	/** @dataProvider returnDestinations */
	public function testReturnDestination(?string $requested, ?string $expected):void {
		$query = ["c" => "cipher", "i" => "iv", "d" => "deployment"];
		if(!is_null($requested)) {
			$query["u"] = bin2hex($requested);
		}
		$uri = new ProviderUri(new Uri("https://login.example.test/?" . http_build_query($query)));
		$default = new Uri("https://app.example.test/callback");
		self::assertTrue($uri->isLoginAttempt());
		$actual = $uri->getReturnUri($default);
		self::assertSame($expected, is_null($actual) ? null : (string)$actual);
	}

	public function returnDestinations():array {
		return [
			["/members/orders?status=open#latest", "https://app.example.test/members/orders?status=open#latest"],
			["https://app.example.test/members?x=1#tab", "https://app.example.test/members?x=1#tab"],
			["https://APP.EXAMPLE.TEST:443/members", "https://app.example.test/members"],
			["?page=2", "https://app.example.test/callback?page=2"],
			["#details", "https://app.example.test/callback#details"],
			["/", "https://app.example.test/"],
			[null, null], ["", null],
			["https://evil.example/steal", null],
			["http://app.example.test/steal", null],
			["https://app.example.test:444/steal", null],
			["https://user@app.example.test/steal", null],
			["//evil.example/steal", null],
			["/\\evil.example/steal", null],
			["/path\r\nLocation: https://evil.example", null],
			["javascript:alert(1)", null],
			["members/orders", null],
			["https://app.example.test:99999/", null],
		];
	}

	/** @dataProvider malformedEncoding */
	public function testMalformedHexIsIgnored(mixed $encoded):void {
		$uri = new ProviderUri(new Uri("https://login.example.test/?" . http_build_query(["u" => $encoded])));
		self::assertNull($uri->getReturnUri(new Uri("https://app.example.test/callback")));
	}

	public function malformedEncoding():array {
		return [["0"], ["zz"], [["value"]]];
	}

	public function testMatchingNonDefaultPortIsPreserved():void {
		$uri = new ProviderUri(new Uri("https://login.example.test/?u=" . bin2hex("https://app.example.test:8443/members")));
		self::assertSame("https://app.example.test:8443/members", (string)$uri->getReturnUri(new Uri("https://app.example.test:8443/callback")));
	}
}
