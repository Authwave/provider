<?php
namespace Authwave\Admin\Security;

use Authwave\Admin\User\ActiveSession;
use Authwave\Model\{ApplicationDeployment, ApplicationSessionPolicy};

readonly class SecurityScore {
	public string $grade;
	/** @var list<SecurityCheck> */
	public array $checks;

	/** @param list<ActiveSession> $sessions */
	public function __construct(?ApplicationSessionPolicy $policy, ?ApplicationDeployment $production,
		array $sessions, string $settingsUrl) {
		$checks = [];

		if($policy === null) {
			$checks []= new SecurityCheck(
				"Save a session policy to require managed clients.", 0, "#sessions");
		}
		else {
			$defaults = new ApplicationSessionPolicy($policy->applicationId);
			$longerTimeouts = $policy->maximumLifetimeMinutes > $defaults->maximumLifetimeMinutes
				|| $policy->idleTimeoutMinutes > $defaults->idleTimeoutMinutes;
			$defaultLimits = "{$defaults->maximumLifetimeMinutes} minutes maximum"
				. " and {$defaults->idleTimeoutMinutes} minutes idle";
			$checks []= new SecurityCheck($longerTimeouts
				? "Session timeouts exceed the app defaults of $defaultLimits."
				: "Managed clients are required, with timeouts at or below the app defaults.",
				$longerTimeouts ? -1 : 1, "#sessions");
		}

		if($production === null) {
			$checks []= new SecurityCheck(
				"Choose a production deployment to assess its connection.", 0, $settingsUrl);
		}
		else {
			$scheme = strtolower($production->getClientReturnUri()->getScheme());

			if($scheme !== "https") {
				$checks []= new SecurityCheck(
					"The production client URL does not use HTTPS.", -1, $settingsUrl);
			}
			elseif($production->domainVerified) {
				$checks []= new SecurityCheck(
					"The production domain is verified and its client URL uses HTTPS.", 1, $settingsUrl);
			}
			else {
				$checks []= new SecurityCheck(
					"Verify the production domain to raise the grade.", 0, $settingsUrl);
			}
		}

		$legacyCount = count(array_filter($sessions,
			fn(ActiveSession $entry) => !$entry->session->managed));

		if($legacyCount > 0) {
			$label = $legacyCount === 1
				? "1 active older-client session cannot"
				: "$legacyCount active older-client sessions cannot";
			$checks []= new SecurityCheck("$label be revoked remotely.", -1, "#sessions");
		}
		else {
			$checks []= new SecurityCheck($sessions === []
				? "No active sessions to assess."
				: "All current sessions support remote revocation.", 0, "#sessions");
		}

		$position = 3 + array_sum(array_map(fn(SecurityCheck $check) => $check->adjustment, $checks));
		$this->grade = ["F", "E", "D", "C", "B", "A"][max(0, min(5, $position))];
		$this->checks = $checks;
	}
}
