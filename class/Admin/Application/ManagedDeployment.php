<?php
namespace Authwave\Admin\Application;

readonly class ManagedDeployment {
	public string $apiKey;
	public function __construct(
		public string $identifier,
		public string $name,
		public string $host,
		public string $clientUrl,
		public string $clientLoginPath,
		public string $secret,
	) {
		$this->apiKey = $identifier . "_" . $secret;
	}
}
