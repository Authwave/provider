<?php
namespace Authwave\Security;

use Authwave\User\User;
use Gt\Database\Query\QueryCollection;

class AdminAccess {
	public function __construct(
		private readonly QueryCollection $db,
		private readonly ?string $adminEmail = null,
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

		return $this->db->fetch(
			"getByUserAndApplication",
			$user->id,
			$user->deployment->application->id,
		) !== null;
	}
}
