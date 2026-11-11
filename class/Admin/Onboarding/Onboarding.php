<?php
namespace Authwave\Admin\Onboarding;

use GT\Http\Uri;
use InvalidArgumentException;

readonly class Onboarding {
	public function __construct(
		private int $applicationCount,
		private bool $hasVerifiedDomain = false,
		private bool $hasConnectedApplication = false,
		private bool $hasClientLogin = false,
	) {
		if($applicationCount < 0) {
			throw new InvalidArgumentException("Application count cannot be negative.");
		}
	}

	public function needsApplication():bool {
		return $this->applicationCount === 0;
	}

	public function redirectFor(Uri $uri):?Uri {
		if(!$this->needsApplication()) {
			return null;
		}

		$path = rtrim($uri->getPath(), "/");

		foreach(["/admin/applications", "/admin/billing", "/admin/integrations"] as $allowed) {
			if($path === $allowed || str_starts_with($path, "$allowed/")) {
				return null;
			}
		}

		if(in_array($path, ["/integrations/help", "/admin/logout"], true)) {
			return null;
		}

		return new Uri("/admin/applications/#first-application");
	}

	public function completedCount():int {
		if($this->needsApplication()) {
			return 1;
		}

		if(!$this->hasVerifiedDomain) {
			return 2;
		}

		if(!$this->hasConnectedApplication) {
			return 3;
		}

		return $this->hasClientLogin ? 5 : 4;
	}

	/** @return iterable<SetupStep> */
	public function steps():iterable {
		$titles = [
			"Create your account",
			"Create your first application",
			"Verify domain",
			"Connect your application",
			"Your first user signs in",
			"Customise your deployment",
			"Enable production mode",
		];

		foreach($titles as $index => $title) {
			$number = $index + 1;
			yield new SetupStep(
				$number,
				$title,
				$number <= $this->completedCount(),
				$number === $this->completedCount() + 1,
			);
		}
	}
}
