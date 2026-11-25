<?php
namespace Authwave\Admin\Security;

readonly class SecurityCheck {
	public function __construct(
		public string $description,
		public int $adjustment,
		public string $url,
	) {}
}
