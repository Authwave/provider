<?php
namespace Authwave\Admin\Application;

use Authwave\Model\ApplicationRepository;
use Authwave\Security\{Audit, Action};
use Authwave\Session\FlashSession;
use GT\Input\Input;
use Gt\Http\{Request, Response};
use InvalidArgumentException;

readonly class ApplicationSettingsAction {
	public function __construct(
		private ApplicationRepository $applications,
		private ApplicationSettingsContext $context,
		private FlashSession $flash,
		private Audit $audit,
	) {}

	public function saveApplication(Input $input, Request $request, Response $response):void {
		if(!$this->accepts($request)) {
			return;
		}

		$application = $this->context->application;

		try {
			$this->applications->save($this->context->actor, $application->identifier,
				new ApplicationName($this->text($input, "name")),
				$this->text($input, "productionDeploymentId"));
		}
		catch(InvalidArgumentException $error) {
			$this->reject($error, Action::APPLICATION_SETTINGS_REJECTED, $response);
			return;
		}

		$this->flash->setFlash("Application settings saved.", "settings_success");
		$response->redirect($application->url);
	}

	public function saveDeployment(Input $input, Request $request, Response $response):void {
		if(!$this->accepts($request)) {
			return;
		}

		$application = $this->context->application;

		try {
			$saved = $this->applications->saveDeployment($this->context->actor, $application->identifier,
				$this->text($input, "deploymentId"), new DeploymentData(
					$this->text($input, "deploymentName"), $this->text($input, "host"),
					$this->text($input, "clientUrl"), $this->text($input, "clientLoginPath"),
				));
		}
		catch(InvalidArgumentException $error) {
			$this->reject($error, Action::APPLICATION_SETTINGS_REJECTED, $response);
			return;
		}

		$this->flash->setFlash("Deployment saved.", "settings_success");
		$response->redirect($application->url . "?deployment=" . rawurlencode($saved->identifier)
			. "#deployment-" . rawurlencode($saved->identifier));
	}

	public function deleteDeployment(Input $input, Request $request, Response $response):void {
		if(!$this->accepts($request)) {
			return;
		}

		try {
			$identifier = $this->text($input, "deploymentId");

			if($identifier === null || trim($identifier) === "") {
				throw new InvalidArgumentException("Choose a deployment assigned to this application.");
			}

			$prompt = $this->context->deletionPrompt($identifier);

			if($prompt === null) {
				throw new InvalidArgumentException("Choose a deployment assigned to this application.");
			}

			if(!$prompt->isConfirmed($input->get("confirmDelete", Input::DATA_BODY))) {
				$this->audit->create(Action::DELETE_CONFIRMATION_REQUESTED,
					["applicationId" => $this->context->application->identifier,
						"deploymentId" => $identifier], $this->context->actor);
				$response->redirect($prompt->confirmationUrl);
				return;
			}

			$this->applications->deleteDeployment($this->context->actor,
				$this->context->application->identifier, $identifier);
		}
		catch(InvalidArgumentException $error) {
			$this->reject($error, Action::DEPLOYMENT_DELETION_REJECTED, $response);
			return;
		}

		$this->flash->setFlash("Deployment deleted.", "settings_success");
		$response->redirect($this->context->application->url);
	}

	private function accepts(Request $request):bool {
		return $request->getMethod() === "POST"
			&& rtrim($request->getUri()->getPath(), "/")
				=== rtrim($this->context->application->url, "/");
	}

	private function reject(InvalidArgumentException $error, Action $action, Response $response):void {
		$this->audit->create($action,
			["applicationId" => $this->context->application->identifier,
				"reason" => $error->getMessage()], $this->context->actor);
		$this->flash->setFlash($error->getMessage(), "settings_error");
		$response->redirect($this->context->application->url);
	}

	private function text(Input $input, string $key):?string {
		$value = $input->get($key, Input::DATA_BODY);

		if($value !== null && !is_string($value)) {
			throw new InvalidArgumentException("Enter a single value for $key.");
		}

		return $value;
	}
}
