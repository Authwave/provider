<?php
namespace Authwave\Security;

use InvalidArgumentException;

readonly class ClientSessionRequest {
	public string $sessionId;
	public bool $active;
	public string $nonce;

	public function __construct(string $body, string $signature, string $secret, ?int $now = null) {
		if(strlen($body) > 1024 || !hash_equals(hash_hmac("sha256", $body, $secret), $signature)) {
			throw new InvalidArgumentException("Invalid session request signature.");
		}

		$data = json_decode($body);

		if(!is_object($data) || !is_string($data->sessionId ?? null)
		|| !preg_match('/^[a-zA-Z0-9]{1,64}$/', $data->sessionId)
		|| !is_bool($data->active ?? null) || !is_int($data->issuedAt ?? null)
		|| abs(($now ?? time()) - $data->issuedAt) > 30
		|| !is_string($data->nonce ?? null) || !preg_match('/^[a-f0-9]{32}$/', $data->nonce)) {
			throw new InvalidArgumentException("Invalid or expired session request.");
		}

		$this->sessionId = $data->sessionId;
		$this->active = $data->active;
		$this->nonce = $data->nonce;
	}

	public function responseSignature(bool $valid, string $secret):string {
		return hash_hmac("sha256", $this->nonce . "\n" . ($valid ? "1" : "0"), $secret);
	}
}
