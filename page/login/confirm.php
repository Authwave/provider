<?php
namespace Authwave\Page\Login;

use Authwave\User\InvalidConfirmationCodeException;
use Authwave\User\User;
use Authwave\User\UserRepository;
use Gt\Input\InputData\InputData;
use Gt\WebEngine\Logic\Page;

class ConfirmPage extends Page {
	public User $user;
	public UserRepository $userRepo;

	public function doConfirm(InputData $data):void {
		try {
			$this->userRepo->confirm(
				$this->user,
				$data->getString("code")
			);
		}
		catch(InvalidConfirmationCodeException $exception) {
			// TODO: Display error and return.
			return;
		}

		// TODO: Create response cipher and redirect.
	}
}