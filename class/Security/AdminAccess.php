<?php
namespace Authwave\Security;

use Authwave\Model\User;
use Gt\Database\Query\QueryCollection;

readonly class AdminAccess {
	public function __construct(
		private QueryCollection $database,
		private ?string $adminEmail = null,
	) {}

	public function allows(?User $user):bool {
		if($user === null) {
			return false;
		}

		foreach(explode(",", $this->adminEmail ?? "") as $email) {
			$email = trim($email);

			if($email !== "" && strcasecmp($user->email, $email) === 0) {
				return true;
			}
		}

		return $this->database->fetch(
			"getByUserAndApplication",
			$user->id,
			$user->deployment->application->id,
		) !== null;
	}
}
