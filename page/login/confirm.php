<?php
namespace Authwave\Page\Login;

use Authwave\Application\ApplicationDeployment;
use Authwave\Crypto\AuthUri;
use Authwave\Crypto\AuthUriFactory;
use Authwave\Crypto\Cipher;
use Authwave\Crypto\Secret;
use Authwave\DataTransfer\RequestData;
use Authwave\UI\Flash;
use Authwave\User\InvalidConfirmationCodeException;
use Authwave\User\User;
use Authwave\User\UserRepository;
use Gt\Input\InputData\InputData;
use Gt\WebEngine\Logic\Page;

class ConfirmPage extends Page {
	const ENCRYPTION_METHOD = "aes128";

	public User $user;
	public UserRepository $userRepo;
	public ApplicationDeployment $deployment;
	public Flash $flash;
	public RequestData $requestData;

	public function go():void {
		$cipher = $this->requestData->getCipher();
		$key = $this->deployment->getClientKey();
	}

	public function doConfirm(InputData $data):void {
		try {
			$code = $data->getString("code");
			$code = str_replace(["-", " "], "", $code);
			$code = trim($code);

			$this->userRepo->confirm(
				$this->user,
				$code
			);
		}
		catch(InvalidConfirmationCodeException $exception) {
			$this->flash->error("Invalid confirmation code");
			$this->reload();
			exit;
		}

		$authUri = AuthUriFactory::buildAuthUri(
			$this->requestData,
			$this->deployment,
			$this->user
		);
		$this->redirect(
			"/login/success?continue="
			. base64_encode($authUri)
		);
		exit;
	}
}