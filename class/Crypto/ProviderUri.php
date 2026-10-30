<?php
namespace Authwave\Crypto;

use Gt\Http\Uri;
use Psr\Http\Message\UriInterface;

class ProviderUri {
	const QUERY_STRING_CIPHER = "c";
	const QUERY_STRING_INIT_VECTOR = "i";
	const QUERY_STRING_CONSUMER_URI = "u";
	const QUERY_STRING_DEPLOYMENT_ID = "d";

	const REQUIRED_QUERY_PARAMETERS = [
		self::QUERY_STRING_CIPHER,
		self::QUERY_STRING_INIT_VECTOR,
		self::QUERY_STRING_DEPLOYMENT_ID,
	];

	public function __construct(private readonly Uri $uri) {}

	public function getDeploymentId():?string {
		if(!$this->isLoginAttempt()) {
			return null;
		}

		return $this->uri->getQueryValue(self::QUERY_STRING_DEPLOYMENT_ID);
	}

	public function getReturnUri(UriInterface $default):?UriInterface {
		$encoded = $this->uri->getQueryValue(self::QUERY_STRING_CONSUMER_URI);
		if(!is_string($encoded) || $encoded === ""
		|| strlen($encoded) % 2 !== 0 || !ctype_xdigit($encoded)) {
			return null;
		}

		$requested = hex2bin($encoded);
		if(preg_match('/[\\\\\x00-\x20\x7f]/', $requested)
		|| str_starts_with($requested, "//")) {
			return null;
		}

		$parts = parse_url($requested);
		if($parts === false || isset($parts["user"]) || isset($parts["pass"])) {
			return null;
		}

		if(isset($parts["scheme"]) || isset($parts["host"])) {
			$scheme = strtolower($parts["scheme"] ?? "");
			$defaultPort = $default->getPort() ?? ($default->getScheme() === "https" ? 443 : 80);
			$port = $parts["port"] ?? ($scheme === "https" ? 443 : 80);
			if($scheme !== strtolower($default->getScheme())
			|| strtolower($parts["host"] ?? "") !== strtolower($default->getHost())
			|| $port !== $defaultPort) {
				return null;
			}
		}
		elseif(isset($parts["path"]) && !str_starts_with($parts["path"], "/")) {
			return null;
		}

		return $default
			->withPath($parts["path"] ?? $default->getPath())
			->withQuery($parts["query"] ?? "")
			->withFragment($parts["fragment"] ?? "");
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
