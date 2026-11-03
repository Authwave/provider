<?php
namespace Authwave\Admin\Application;

readonly class ManagedApplication {
	public string $settingsUrl;
	public string $client;
	public string $loginHost;
	public function __construct(
		public string $identifier,
		public string $name,
		?string $client = null,
		?string $loginHost = null,
	) {
		$this->settingsUrl = "/admin/applications/" . rawurlencode($identifier) . "/";
		$this->client = $client === null || $client === "" ? "-" : $client;
		$this->loginHost = $loginHost === null || $loginHost === "" ? "-" : $loginHost;
	}
}
