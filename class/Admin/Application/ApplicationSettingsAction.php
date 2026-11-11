<?php
namespace Authwave\Admin\Application;

use Authwave\Model\{ApplicationRepository, Application, User};
use Authwave\UI\DeletionPrompt;
use Authwave\Security\{Audit, Action};
use Authwave\Session\FlashSession;
use GT\Input\Input;
use GT\Dom\Element;
use GT\DomValidation\Validator;
use GT\DomValidation\ValidationException;
use GT\Http\{Request, Response};
use InvalidArgumentException;

readonly class ApplicationSettingsAction {
	private string $url;
	public function __construct(
		private ApplicationRepository $applications,
		private Application $application,
		private User $actor,
		private FlashSession $flash,
		private Audit $audit,
	) {
		$this->url = "/admin/applications/" . rawurlencode($application->id) . "/";
	}

	public function saveApplication(Input $input, Request $request, Response $response):void {
		if(!$this->accepts($request)) {
			return;
		}

		$application = $this->application;

		try {
			$this->applications->save($this->actor, $application->id,
				new ApplicationName($this->text($input, "name")),
				$this->text($input, "productionApplicationDeploymentId"));
		}
		catch(InvalidArgumentException $error) {
			$this->reject($error, Action::APPLICATION_SETTINGS_REJECTED);
			$response->redirect($this->url);
			return;
		}

		$this->flash->setFlash("Application settings saved.", "settings_success");
		$response->redirect($this->url);
	}

	public function saveDeployment(Input $input, Request $request, Response $response,
		Element $form):void {
		if(!$this->accepts($request)) {
			return;
		}

		$application = $this->application;
		$validator = new Validator();

		try {
			$fields = [];

			foreach($form->querySelectorAll("input[name]:not([readonly])") as $field) {
				$name = $field->getAttribute("name");
				$fields[$name] = $this->text($input, $name);
			}

			$validator->validate($form, $fields);
			$saved = $this->applications->saveDeployment($this->actor, $application->id,
				$this->text($input, "id"), new DeploymentData(
					(string)$fields["title"], (string)$fields["providerHost"],
					(string)$fields["clientHost"], (string)$fields["clientLoginPath"],
				));
		}
		catch(ValidationException) {
			$messages = [];

			foreach($validator->getLastErrorList() as $name => $message) {
				$field = $form->querySelector('[name="' . $name . '"]');
				$label = $field->closest("label")->querySelector("span")->textContent;
				$messages []= "$label: $message";
			}

			$this->reject(new InvalidArgumentException(implode(" ", $messages)),
				Action::APPLICATION_SETTINGS_REJECTED, "deployment_error");
			return;
		}
		catch(InvalidArgumentException $error) {
			$this->reject($error, Action::APPLICATION_SETTINGS_REJECTED, "deployment_error");
			return;
		}

		$this->flash->setFlash("Deployment saved.", "deployment_success");
		$response->redirect($this->url . "?deployment=" . rawurlencode($saved->id)
			. "#deployment-" . rawurlencode($saved->id));
	}

	public function deleteDeployment(Input $input, Request $request, Response $response):void {
		if(!$this->accepts($request)) {
			return;
		}

		try {
			$identifier = $this->text($input, "id");

			if($identifier === null || trim($identifier) === "") {
				throw new InvalidArgumentException("Choose a deployment assigned to this application.");
			}

			$prompt = $this->deletionPrompt($identifier);

			if($prompt === null) {
				throw new InvalidArgumentException("Choose a deployment assigned to this application.");
			}

			if(!$prompt->isConfirmed($input->get("confirmDelete", Input::DATA_BODY))) {
				$this->audit->create(Action::DELETE_CONFIRMATION_REQUESTED,
					["applicationId" => $this->application->id,
						"deploymentId" => $identifier], $this->actor);
				$response->redirect($prompt->confirmationUrl);
				return;
			}

			$this->applications->deleteDeployment($this->actor,
				$this->application->id, $identifier);
		}
		catch(InvalidArgumentException $error) {
			$this->reject($error, Action::DEPLOYMENT_DELETION_REJECTED);
			$response->redirect($this->url);
			return;
		}

		$this->flash->setFlash("Deployment deleted.", "settings_success");
		$response->redirect($this->url);
	}

	public function deletionPrompt(?string $identifier):?DeletionPrompt {
		foreach($this->applications->deployments($this->actor, $this->application->id) as $deployment) {
			if($deployment->id === $identifier) {
				return new DeletionPrompt($deployment->id, $deployment->title, $this->url);
			}
		}

		return null;
	}

	private function accepts(Request $request):bool {
		return $request->getMethod() === "POST"
			&& rtrim($request->getUri()->getPath(), "/")
				=== rtrim($this->url, "/");
	}

	private function reject(InvalidArgumentException $error, Action $action,
		string $category = "settings_error"):void {
		$this->audit->create($action,
			["applicationId" => $this->application->id,
				"reason" => $error->getMessage()], $this->actor);
		$this->flash->setFlash($error->getMessage(), $category);
	}

	private function text(Input $input, string $key):?string {
		$value = $input->get($key, Input::DATA_BODY);

		if($value !== null && !is_string($value)) {
			throw new InvalidArgumentException("Enter a single value for $key.");
		}

		return $value;
	}
}
