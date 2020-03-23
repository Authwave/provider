<?php
namespace Authwave\User;

use Authwave\Application\Application;
use Authwave\Application\ApplicationDeployment;
use Gt\Database\Query\QueryCollection;
use Gt\Session\SessionStore;

class UserRepository {
	public const SESSION_KEY = "authwave.user";

	private QueryCollection $db;
	private SessionStore $session;

	public function __construct(
		QueryCollection $db,
		SessionStore $session
	) {
		$this->db = $db;
		$this->session = $session;
	}

	public function getUserInDeployment(
		int $deploymentId,
		string $email
	):User {
		$row = $this->db->fetch(
			"getUserInDeployment", [
			"deploymentId" => $deploymentId,
			"email" => $email,
		]);
		if(!$row) {
			throw new UserEmailNotFoundInDeploymentException(
				"$email ($deploymentId)"
			);
		}

		$application = new Application(
			$row->getInt("applicationId"),
			$row->getString("displayName")
		);

		$deployment = new ApplicationDeployment(
			$application,
			$row->getInt("deploymentId"),
			$row->getString("clientKey"),
			$row->getString("clientHost"),
			$row->getString("clientLoginHost")
		);

		return new User(
			$deployment,
			$row->getInt("userId"),
			$row->getString("uuid"),
			$row->getString("email")
		);
	}
}