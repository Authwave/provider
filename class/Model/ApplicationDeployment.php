<?php
namespace Authwave\Model;

use GT\Http\Uri;
use Psr\Http\Message\UriInterface;

readonly class ApplicationDeployment {
	public string $applicationId;

	/** Retains the public id field and named parameter contract.
	* @SuppressWarnings("PHPMD.ShortVariable")
	*/
	public function __construct(
		public string $id,
		public Application $application,
		public string $title,
		public string $secret,
		public string $clientHost,
		public string $clientLoginPath,
		public string $providerHost = "",
		public bool $domainVerified = false,
		public bool $clientConnected = false,
		public bool $userLoggedIn = false,
	) {
		$this->applicationId = $application->id;
	}

	public function getClientReturnUri():UriInterface {
		$scheme = "https";

		$host = $this->clientHost;
		$schemeSeparator = strpos($host, "://");

		if($schemeSeparator !== false) {
			$scheme = strtok($host, "://");
			$host = substr($host, $schemeSeparator + 3);
		}

		return (new Uri())
			->withScheme($scheme)
			->withHost($host)
			->withPath($this->clientLoginPath);
	}
	public function getProviderHost():string {
		// Old serialised sessions may lack this field entirely.
		return get_object_vars($this)["providerHost"] ?? "";
	}
}
