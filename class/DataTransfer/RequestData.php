<?php
namespace Authwave\DataTransfer;

use Gt\Session\SessionStore;

class RequestData {
	public const SESSION_REQUEST_DATA = "authwave.requestData";

	private string $id;
	private string $cipher;
	private string $iv;
	private string $path;

	public function __construct(
		string $id,
		string $cipher,
		string $iv,
		string $path
	) {
		$this->id = $id;
		$this->cipher = $cipher;
		$this->iv = $iv;
		$this->path = $path;
	}

	public function getId():string {
		return $this->id;
	}
}