<?php
namespace Authwave\User;

use Authwave\Site\Site;

class User {
	public function __construct(
		public readonly string $id,
		public readonly Site $site,
		public readonly string $email,
	) {}
}
