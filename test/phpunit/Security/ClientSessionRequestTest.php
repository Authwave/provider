<?php
namespace Authwave\Test\Security;

use Authwave\Security\ClientSessionRequest;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClientSessionRequestTest extends TestCase {
	#[DataProvider("invalidRequests")]
	public function testConstruct_rejectsTamperingExpiredRequestsAndMalformedFields(string $body, string $key):void {
		$this->expectException(InvalidArgumentException::class);
		$sut = new ClientSessionRequest($body, hash_hmac("sha256", $body, $key), "secret", 100);
	}

	public static function invalidRequests():iterable {
		$valid = ["sessionId" => "session", "active" => true, "nonce" => str_repeat("a", 32), "issuedAt" => 100];
		yield [json_encode($valid), "wrong-key"];

		foreach(["issuedAt" => 69, "active" => "yes", "sessionId" => [], "nonce" => "invalid"] as $field => $value) {
			yield [json_encode([$field => $value] + $valid), "secret"];
		}
	}

	public function testResponseSignature_isBoundToTheRequestNonceAndValidationResult():void {
		$body = json_encode(["sessionId" => "session", "active" => false,
			"nonce" => str_repeat("a", 32), "issuedAt" => 100]);
		$sut = new ClientSessionRequest($body, hash_hmac("sha256", $body, "secret"), "secret", 100);

		self::assertSame("session", $sut->sessionId);
		self::assertFalse($sut->active);
		self::assertNotSame($sut->responseSignature(true, "secret"), $sut->responseSignature(false, "secret"));
		self::assertSame(hash_hmac("sha256", str_repeat("a", 32) . "\n1", "secret"), $sut->responseSignature(true, "secret"));
	}
}
