<?php
namespace Authwave\DataTransfer;

class RequestData {
	public const SESSION_REQUEST_DATA = "authwave.requestData";

	private string $cipher;
	private string $iv;
	private string $path;

	public function __construct(
		string $cipher,
		string $iv,
		string $path
	) {
		$this->cipher = $cipher;
		$this->iv = $iv;
		$this->path = hex2bin($path);
	}

	public function getPath():string {
		return $this->path;
	}

	public function getCipher():string {
		return $this->cipher;
	}

	public function getIv():string {
		return $this->iv;
	}
}