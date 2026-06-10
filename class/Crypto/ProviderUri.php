<?php
namespace Authwave\Crypto;

use Gt\Http\Uri;

class ProviderUri {
	const QUERY_STRING_CIPHER = "c";
	const QUERY_STRING_INIT_VECTOR = "i";
	const QUERY_STRING_CONSUMER_URI = "u";
	const QUERY_STRING_DEPLOYMENT_ID = "d";

	const REQUIRED_QUERY_PARAMETERS = [
		self::QUERY_STRING_CIPHER,
		self::QUERY_STRING_INIT_VECTOR,
		self::QUERY_STRING_CONSUMER_URI,
		self::QUERY_STRING_DEPLOYMENT_ID,
	];

	public function __construct(private readonly Uri $uri) {}

	public function getDeploymentId():?string {
		if(!$this->isLoginAttempt()) {
			return null;
		}

		return $this->uri->getQueryValue(self::QUERY_STRING_DEPLOYMENT_ID);
	}

	public function isLoginAttempt():bool {
		foreach(self::REQUIRED_QUERY_PARAMETERS as $param) {
			if(!$this->uri->getQueryValue($param)) {
				return false;
			}
		}

		return true;
	}
}
