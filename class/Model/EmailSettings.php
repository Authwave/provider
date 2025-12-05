<?php
namespace Authwave\Model;

class EmailSettings {
	public function __construct(
		public ?string $host,
		public ?int $port,
		public ?string $username,
		public ?string $password,
	) {}
}
