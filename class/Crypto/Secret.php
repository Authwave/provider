<?php
namespace Authwave\Crypto;

use Authwave\DataTransfer\RequestData;

class Secret {
	const DECRYPTION_METHOD = "aes128";

	private string $secretIv;
	private ?string $message;

	public function __construct(
		RequestData $requestData,
		string $clientKey
	) {
		$decrypted = $this->decryptIv(
			base64_decode($requestData->getCipher()),
			hex2bin($requestData->getIv()),
			$clientKey
		);

		$message = null;
		if(strstr($decrypted, "|")) {
			[$secretIv, $message] = explode("|", $decrypted);
		}
		else {
			$secretIv = $decrypted;
		}

		$this->secretIv = $secretIv;
		$this->message = $message;
	}

	public function getMessage():?string {
		return $this->message;
	}

	public function getMessageKey(string $key):?string {
		$message = $this->getMessage();

		if(is_null($message)
		|| !strstr($message, "=")) {
			return null;
		}

		list($messageKey, $value) = explode("=", $message);

		if($key !== $messageKey) {
			return null;
		}

		return $value;
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