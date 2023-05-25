<?php
namespace Authwave\Model;

use Gt\Cipher\Key;
use Gt\Http\Uri;

class Application {
	public function __construct(
		public readonly string $id,
		public readonly string $name
	) {}
}
