<?php
namespace Authwave\Page\Login;

use Authwave\Application\Application;
use Authwave\Application\ApplicationDeployment;
use Authwave\Application\ApplicationRepository;
use Authwave\Crypto\AuthUriFactory;
use Authwave\DataTransfer\RequestData;
use Authwave\User\User;
use Authwave\User\UserFieldException;
use Authwave\User\UserRepository;
use Gt\Input\InputData\InputData;
use Gt\WebEngine\Logic\Page;

class SignupPage extends Page {
	public Application $application;
	public ApplicationDeployment $deployment;
	public ApplicationRepository $appRepo;
	public UserRepository $userRepo;
	public User $user;
	public RequestData $requestData;

	public function go():void {
		foreach($this->appRepo->getApplicationFields($this->application)
		as $field) {
			$t = $this->document->getTemplate("field");
			$t->bindKeyValue("displayName", $field->getDisplayName());
			$t->bindKeyValue("name", $field->getName());
			$t->bindKeyValue("hint", $field->getHint());
			$t->bindKeyValue("required", $field->isRequired());

			$t->insertTemplate();
		}

		$this->document->querySelector("input")->setAttribute("autofocus", true);
	}

	public function doSignup(InputData $data):void {
// TODO: Serverside validation for required fields and field types.
		try {
			$this->userRepo->setFields($this->user, $data->asArray());
			$this->user = $this->userRepo->load(true);
		}
		catch(UserFieldException $exception) {
			$this->reload();
			exit;
		}

		$authUri = AuthUriFactory::buildAuthUri(
			$this->requestData,
			$this->deployment,
			$this->user
		);
		$this->userRepo->setLastLogin($this->user);

		$this->redirect(
			"/login/success?continue="
			. $authUri->encode()
		);
		exit;
	}
}