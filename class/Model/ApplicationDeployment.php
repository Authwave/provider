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
		return (new Uri())
			->withHost($this->clientHost)
			->withPath($this->clientLoginPath);
	}
}
