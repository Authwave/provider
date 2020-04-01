<?php
namespace Authwave\Crypto;

use Authwave\Application\ApplicationDeployment;
use Authwave\DataTransfer\RequestData;
use Authwave\User\User;

class AuthUriFactory {
	public static function buildAuthUri(
		RequestData $requestData,
		ApplicationDeployment $deployment,
		User $user
	):AuthUri {
		return new AuthUri(
			new Cipher(
				new Secret(
					$requestData,
					$deployment->getClientKey()
				),
				$user
			),
			$deployment->getClientHost(),
			$requestData->getPath(),
		);
	}
}