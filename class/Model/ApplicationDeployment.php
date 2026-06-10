<?php
namespace Authwave\Model;

use Gt\Http\Uri;
use Psr\Http\Message\UriInterface;

class ApplicationDeployment {
	public function __construct(
		public readonly string $id,
		public readonly Application $application,
//		#[DefaultValue("default")]
		public readonly string $title,
		public readonly string $secret,
		public readonly string $clientHost,
//		#[DefaultValue("/")]
		public readonly string $clientLoginPath,
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
}
