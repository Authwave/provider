<?php
namespace Authwave\Crypto;

use Gt\Http\Uri;
use Psr\Http\Message\UriInterface;

class AuthUri Extends Uri {
	const RESPONSE_QUERY_PARAMETER = "AUTHWAVE_RESPONSE_DATA";

	public function __construct(
		Cipher $cipher,
		UriInterface $clientHost,
		string $returnTo
	) {
		$returnToQuery = parse_url($returnTo, PHP_URL_QUERY);
		parse_str($returnToQuery, $returnToQueryParts);
		$returnToQueryParts[self::RESPONSE_QUERY_PARAMETER] = (string)$cipher;

		$baseUri = $clientHost
			->withPath($returnTo)
			->withQuery(http_build_query($returnToQueryParts));
		$baseUri = $this->normaliseBaseUri($baseUri);

		parent::__construct($baseUri);
	}

	private function normaliseBaseUri(string $baseUri):Uri {
		$scheme = parse_url($baseUri, PHP_URL_SCHEME)
			?? "https";
		$host = parse_url($baseUri, PHP_URL_HOST)
			?? parse_url($baseUri, PHP_URL_PATH);
		$port = parse_url($baseUri, PHP_URL_PORT)
			?? null;
		$query = parse_url($baseUri, PHP_URL_QUERY)
			?? "";

		$uri = (new Uri())
			->withScheme($scheme)
			->withHost($host)
			->withPort($port)
			->withQuery($query);

		if($uri->getHost() !== "localhost"
			&& $uri->getScheme() !== "https") {
			throw new InsecureProtocolException($uri->getScheme());
		}

		return $uri;
	}

	public function encode():string {
		return base64_encode((string)$this);
	}
}