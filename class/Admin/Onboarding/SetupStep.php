<?php
namespace Authwave\Admin\Onboarding;

readonly class SetupStep {
	public string $icon;
	public function __construct(
		public int $number,
		public string $title,
		public bool $complete,
		public bool $current,
	) {
		$this->icon = $complete ? "circle-check" : "shield";
	}
}
