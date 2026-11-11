<?php
namespace Authwave\Admin\Application;

use Authwave\Model\ApplicationRepository;
use Authwave\Model\User;
use GT\Session\SessionStoreInterface;
use Authwave\Security\{Audit, Action};

readonly class ApplicationCatalogue {
	/** @var list<ManagedApplication> */
	private array $applications;
	private ?ManagedApplication $selected;

	public function __construct(
		ApplicationRepository $store,
		User $administrator,
		SessionStoreInterface $session,
		Audit $audit,
		?string $requested = null,
	) {
		$this->applications = $store->allFor($administrator);
		$requested ??= $session->getString("selected");
		$selected = null;

		foreach($this->applications as $application) {
			if($application->identifier === $requested) {
				$selected = $application;
			}
		}

		$this->selected = $selected ?? ($this->applications[0] ?? null);

		if($session->getString("selected") !== $this->selected?->identifier) {
			$audit->recordChange(
				Action::APPLICATION_SELECTED,
				before: (object)[
					"applicationId" => $session->getString("selected"),
				],
				after: (object)[
					"applicationId" => $this->selected?->identifier,
				],
				actor: $administrator,
			);
		}

		$session->set("selected", $this->selected?->identifier);
	}

	/** @return list<ManagedApplication> */
	public function all():array {
		return $this->applications;
	}

	public function selected():?ManagedApplication {
		return $this->selected;
	}

	/** @return iterable<ApplicationOption> */
	public function options():iterable {
		foreach($this->applications as $application) {
			yield new ApplicationOption($application->identifier, $application->name,
				$application === $this->selected);
		}
	}
}
