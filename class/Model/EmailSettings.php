<?php
namespace Authwave\Model;

readonly class EmailSettings {
	public function __construct(
		public ?string $host,
		public ?int $port,
		public ?string $username,
		public ?string $password,
	) {}
}
