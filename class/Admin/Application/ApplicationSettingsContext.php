<?php
namespace Authwave\Admin\Application;

use Authwave\Model\ApplicationRepository;
use Authwave\Model\User;
use Authwave\UI\DeletionPrompt;
use GT\Input\Input;

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
	public function deploymentPanels(?Input $input = null, string $error = ""):iterable {
		$identifier = $input?->get("deploymentId", Input::DATA_BODY);
		$matched = false;

		foreach($this->deployments as $deployment) {
			$submitted = $input !== null && $identifier === $deployment->identifier;
			$open = $input === null && $this->openedDeployment === $deployment->identifier;

			if($submitted) {
				$matched = true;
				yield new DeploymentPanel($deployment, true, $input, $error);
			}
			else {
				yield new DeploymentPanel($deployment, $open);
			}
		}

		if($input !== null && !$matched) {
			yield new DeploymentPanel(null, true, $input, $error);
		}
		else {
			yield new DeploymentPanel(null);
		}
	}
}
