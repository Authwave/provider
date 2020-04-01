<?php
namespace Authwave\Crypto;

use Authwave\DataTransfer\RequestData;

class Secret {
	const DECRYPTION_METHOD = "aes128";

	private string $secretIv;

	public function __construct(
		RequestData $requestData,
		string $clientKey
	) {
		$this->secretIv = $this->decryptIv(
			base64_decode($requestData->getCipher()),
			hex2bin($requestData->getIv()),
			$clientKey
		);
	}

	private function decryptIv(
		string $requestCipher,
		string $requestIv,
		string $key
	):string {
		return openssl_decrypt(
			$requestCipher,
			self::DECRYPTION_METHOD,
			$key,
			0,
			$requestIv
		);
	}

	public function getBytes():string {
		return hex2bin($this->secretIv);
	}
}