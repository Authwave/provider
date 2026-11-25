<?php
namespace Authwave\Model;

use InvalidArgumentException;

readonly class ApplicationSessionPolicy {
	public function __construct(
		public string $applicationId,
		public int $maximumLifetimeMinutes = 30,
		public int $idleTimeoutMinutes = 15,
	) {
		if($maximumLifetimeMinutes < 1 || $maximumLifetimeMinutes > 65535
		|| $idleTimeoutMinutes < 1 || $idleTimeoutMinutes > $maximumLifetimeMinutes) {
			throw new InvalidArgumentException("Choose timeouts between 1 and 65535 minutes, "
				. "with idle timeout no longer than maximum lifetime.");
		}
	}
}
