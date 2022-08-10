<?php
namespace Authwave\Site;

use Gt\Cipher\Key;
use Gt\Http\Uri;

class Site {
	public function __construct(
		public readonly string $id,
		public readonly string $host,
		public readonly Uri $uri,
		public readonly Key $key,
		public readonly string $name,
	) {}
}
