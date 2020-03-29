<?php
namespace Authwave\User;

use Authwave\Application\Application;
use Authwave\Application\ApplicationDeployment;
use Authwave\DataTransfer\LoginData;
use Authwave\Email\ConfirmationCode;
use Gt\Database\Query\QueryCollection;
use Gt\Session\SessionStore;

class UserRepository {
	public const SESSION_KEY = "authwave.user";
	public const SESSION_USER_OBJECT = "authwave_user_object";

	private QueryCollection $db;
	private SessionStore $session;

	public function __construct(
		QueryCollection $db,
		SessionStore $session
	) {
		$this->db = $db;
		$this->session = $session;
	}

	public function load():?User {
		return $this->session->get(self::SESSION_USER_OBJECT);
	}

	public function save(User $user):void {
		$this->session->set(self::SESSION_USER_OBJECT, $user);
	}

	public function getOrCreate(
		LoginData $loginData,
		ApplicationDeployment $deployment
	):User {
		try {
			$user = $this->getByDeployment(
				$loginData->getEmail(),
				$deployment
			);
		}
		catch(UserEmailNotFoundInDeploymentException $exception) {
			$userId = $this->createInDeployment(
				$loginData->getEmail(),
				$deployment
			);
			$user = $this->getById($userId);
		}

		return $user;
	}

	public function getByDeployment(
		string $email,
		ApplicationDeployment $deployment
	):?User {
		$row = $this->db->fetch(
			"getByDeployment", [
			"email" => $email,
			"deploymentId" => $deployment->getId(),
		]);
		if(!$row) {
			throw new UserEmailNotFoundInDeploymentException(
				"$email ({$deployment->getId()})"
			);
		}

		return new User(
			$deployment,
			$row->getInt("userId"),
			$row->getString("uuid"),
			$row->getString("email")
		);
	}

	public function createInDeployment(
		string $email,
		ApplicationDeployment $deployment
	):int {
		$uuid = bin2hex(random_bytes(16));

		return $this->db->insert(
			"createUser", [
			"uuid" => $uuid,
			"email" => $email,
			"deploymentId" => $deployment->getId()
		]);
	}

	public function getById(int $id):User {
		$row = $this->db->fetch("getById", $id);

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

	/**
	 * Return void if the login is successful.
	 */
	public function handleLogin(
		User $user,
		string $type,
		?string $data
	):void {
		$exception = new LoginNeedsEmailConfirmationException();

		switch($type) {
		case LoginData::TYPE_PASSWORD:
			$detail = $this->db->fetchString(
				"getConfirmedIdentificationDetail",
				$user->getId(),
				$type
			);

			if($detail
			&& password_verify($data, $detail)) {
				return;
			}

			$id = $this->db->insert(
				"setIdentificationDetail", [
				"userId" => $user->getId(),
				"type" => LoginData::TYPE_PASSWORD,
				"detail" => password_hash($data, PASSWORD_DEFAULT),
			]);

			$exception->setId($id);
			break;

		case LoginData::TYPE_EMAIL:
// There is no way to be "successful" when the user is requesting email login
// as a confirmation email _is_ the identification mechanism.
			$id = $this->db->insert(
				"setIdentificationDetail", [
				"userId" => $user->getId(),
				"type" => LoginData::TYPE_EMAIL,
				"detail" => $user->getEmail(),
			]);

			$exception->setId($id);
			break;
		}

		throw $exception;
	}

	public function storeConfirmationCode(
		string $code,
		int $id
	) {
		$this->db->update(
			"setIdentificationCode",
			$code,
			$id
		);
	}

	public function confirm(User $user, string $code):void {
		$row = $this->db->fetch(
			"getIdentification",
			$user->getId(),
			$code
		);

		if(!$row) {
			throw new InvalidConfirmationCodeException($code);
		}

		$this->db->update(
			"confirm",
			$user->getId(),
			$code
		);
	}
}