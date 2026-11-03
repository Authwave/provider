<?php
namespace Authwave\Admin\Application;

readonly class ApplicationOption {
	public function __construct(
		public string $value,
		public string $label,
		public bool $selected,
	) {}
}
