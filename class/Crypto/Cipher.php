<?php
namespace Authwave\Crypto;

use Authwave\User\User;

class Cipher {
	const ENCRYPTION_METHOD = "aes128";

	private Secret $secret;
	private User $user;

	public function __construct(
		Secret $secret,
		User $user
	) {
		$this->secret = $secret;
		$this->user = $user;
	}

	public function __toString():string {
		$userData = [
			"uuid" => $this->user->getUuid(),
			"email" => $this->user->getEmail(),
		];

		$rawCipher = openssl_encrypt(
			serialize($userData),
			self::ENCRYPTION_METHOD,
			$this->user->getDeployment()->getClientKey(),
			0,
			$this->secret->getBytes()
		);

		return base64_encode($rawCipher);
	}
}