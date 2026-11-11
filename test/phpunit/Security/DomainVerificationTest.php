<?php
namespace Authwave\Test\Security;

use Authwave\Security\DomainVerification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DomainVerificationTest extends TestCase {
	#[DataProvider("dnsResponses")]
	public function testVerify_requiresBothNameserversAndTheConfiguredTxt(array|false $ns, array|false $txt,
		bool $expected):void {
		$lookups = [];
		$sut = new DomainVerification("custom-marker", function(string $host, int $type)use($ns, $txt, &$lookups) {
			$lookups []= [$host, $type];
			return $type === DNS_NS ? $ns : $txt;
		});

		self::assertSame($expected, $sut->verify("LOGIN.EXAMPLE.INVALID:8080"));
		self::assertSame(["login.example.invalid", DNS_NS], $lookups[0]);
		if(count($lookups) === 2) {
			self::assertSame(["authwave-confirmation.login.example.invalid", DNS_TXT], $lookups[1]);
		}
	}

	public function testVerify_looksUpTheNamedConfirmationRecordWithinTheDeploymentDomain():void {
		$lookups = [];
		$sut = new DomainVerification(lookup: function(string $host, int $type)use(&$lookups):array {
			$lookups []= [$host, $type];
			return $type === DNS_NS
				? [["type" => "NS", "target" => "ns1.authwave.com"], ["type" => "NS", "target" => "ns2.authwave.com"]]
				: [["type" => "TXT", "txt" => "dev-2611"]];
		});

		self::assertTrue($sut->verify("dev-test.authwave.com"));
		self::assertSame([["dev-test.authwave.com", DNS_NS], ["authwave-confirmation.dev-test.authwave.com", DNS_TXT]], $lookups);
	}

	public static function dnsResponses():iterable {
		$ns = [["type" => "NS", "target" => "ns2.authwave.com."],
			["type" => "NS", "target" => "NS1.AUTHWAVE.COM."]];
		$txt = [["type" => "TXT", "txt" => "custom-marker"]];
		yield "matching records" => [$ns, $txt, true];
		yield "missing second nameserver" => [[$ns[0]], $txt, false];
		yield "duplicates are not both nameservers" => [[$ns[0], $ns[0]], $txt, false];
		yield "no nameservers" => [[], $txt, false];
		yield "lookup failed" => [false, $txt, false];
		yield "TXT lookup failed" => [$ns, false, false];
		yield "no TXT" => [$ns, [], false];
		yield "incorrect marker" => [$ns, [["type" => "TXT", "txt" => "dev-2611"]], false];
		yield "exact marker only" => [$ns, [["type" => "TXT", "txt" => "custom-marker "]], false];
		yield "unrelated TXT is ignored" => [$ns, [["type" => "TXT", "txt" => "other"], $txt[0]], true];
		yield "malformed NS" => [[["type" => "NS"]], $txt, false];
	}

	public function testVerify_doesNotQueryTxtBeforeDelegationMatches():void {
		$calls = 0;
		$sut = new DomainVerification(lookup: function(string $host, int $type)use(&$calls):array {
			$calls++;
			self::assertSame(DNS_NS, $type);
			return [];
		});

		self::assertFalse($sut->verify("login.example.invalid"));
		self::assertSame(1, $calls);
	}

	public function testVerify_localDomainsDoNotPerformDnsLookups():void {
		$sut = new DomainVerification(lookup: function():never {
			self::fail("Local domains must not perform a DNS lookup.");
		});

		self::assertFalse($sut->verify("localhost:8080"));
	}

	#[DataProvider("reservedTestDomains")]
	public function testVerify_testSuffixBypassesDns(string $host, bool $expected):void {
		$lookups = 0;
		$sut = new DomainVerification(lookup: function()use(&$lookups):array {
			$lookups++;
			return [];
		});

		self::assertSame($expected, $sut->verify($host));
		self::assertSame($expected ? 0 : 1, $lookups);
	}

	public static function reservedTestDomains():iterable {
		yield ["login.test", true];
		yield ["LOGIN.TEST:8080", true];
		yield ["app.login.test.", true];
		yield ["test", false];
		yield ["login.test.example.com", false];
		yield ["login.test-extra", false];
		yield ["login.authwave-test", false];
	}

	public function testVerify_temporaryResolverWarningsLeaveDomainUnverified():void {
		$sut = new DomainVerification(lookup: function():false {
			trigger_error("Temporary DNS failure", E_USER_WARNING);
			return false;
		});

		self::assertFalse($sut->verify("login.example.invalid"));
	}

	#[DataProvider("hosts")]
	public function testIsLocal_onlyLocalhostAndLoopbackAddressesAreExempt(string $host, bool $expected):void {
		$sut = new DomainVerification();

		self::assertSame($expected, $sut->isLocal($host));
	}

	public static function hosts():iterable {
		yield ["localhost", true];
		yield ["LOCALHOST:8080", true];
		yield ["127.0.0.1", true];
		yield ["127.23.45.67:9105", true];
		yield ["login.example.test", false];
		yield ["localhost.example.test", false];
		yield ["127.example.test", false];
		yield ["127.0.0.999", false];
		yield ["192.0.2.1", false];
		yield ["", false];
	}

	public function testHostname_removesPortAndNormalisesDnsNames():void {
		$sut = new DomainVerification();

		self::assertSame("login.example.test", $sut->hostname("LOGIN.EXAMPLE.TEST.:8080"));
	}
}
