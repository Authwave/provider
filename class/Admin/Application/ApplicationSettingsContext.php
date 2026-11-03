<?php
namespace Authwave\Admin\Application;

use Authwave\Model\ApplicationRepository;
use Authwave\Model\User;
use Authwave\UI\DeletionPrompt;

readonly class ApplicationSettingsContext {
	public ApplicationSettings $application;
	/** @var list<ManagedDeployment> */
	public array $deployments;
	public function __construct(
		ApplicationRepository $store,
		public User $actor,
		string $identifier,
		private ?string $openedDeployment = null,
	) {
		$this->application = $store->get($actor, $identifier);
		$this->deployments = $store->deployments($actor, $identifier);
	}

	public function deletionPrompt(?string $identifier):?DeletionPrompt {
		foreach($this->deployments as $deployment) {
			if($deployment->identifier === $identifier) {
				return new DeletionPrompt($deployment->identifier, $deployment->name,
					$this->application->url);
			}
		}

		return null;
	}

	/** @return iterable<ApplicationOption> */
	public function productionOptions():iterable {
		if(!$this->deployments) {
			return;
		}
		yield new ApplicationOption("", "None", $this->application->productionDeploymentId === null);

		foreach($this->deployments as $deployment) {
			yield new ApplicationOption($deployment->identifier, $deployment->name,
				$deployment->identifier === $this->application->productionDeploymentId);
		}
	}

	/** @return iterable<DeploymentPanel> */
	public function deploymentPanels():iterable {
		foreach($this->deployments as $deployment) {
			yield new DeploymentPanel($deployment, $this->openedDeployment === $deployment->identifier);
		}
		yield new DeploymentPanel(null, !$this->deployments);
	}
}
