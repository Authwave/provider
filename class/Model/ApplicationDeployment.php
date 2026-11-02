<?php
namespace Authwave\Model;

use Gt\Http\Uri;
use Psr\Http\Message\UriInterface;

class ApplicationDeployment {
	/** Retains the public id field and named parameter contract.
	* @SuppressWarnings("PHPMD.ShortVariable")
	*/
	public function __construct(
		public readonly string $id,
		public readonly Application $application,
//		#[DefaultValue("default")]
		public readonly string $title,
		public readonly string $secret,
		public readonly string $clientHost,
//		#[DefaultValue("/")]
		public readonly string $clientLoginPath,
		public readonly string $providerHost = "",
	) {
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
