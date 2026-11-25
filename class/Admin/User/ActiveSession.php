<?php
namespace Authwave\Admin\User;

use Authwave\Model\UserSession;

readonly class ActiveSession {
	public function __construct(
		public UserSession $session,
		public string $email,
		public string $deploymentId,
		public string $deploymentTitle,
	) {}
}
