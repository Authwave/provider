<?php

namespace Authwave\User;

use Authwave\Email\EmailRepository;
use Authwave\Model\ApplicationDeployment;
use Authwave\Model\ApplicationRepository;
use Authwave\Security\Audit;
use Authwave\Security\Action;
use Gt\Database\Query\QueryCollection;
use Gt\Database\Result\Row;
use Gt\Logger\Log;
use GT\Ulid\Ulid;
class UserRepository {

	public function __construct(
		private readonly QueryCollection $database,
		private readonly ApplicationRepository $applicationRepo,
		private readonly EmailRepository $emailer,
		private readonly Audit $audit
	) {
	}
	public function get(ApplicationDeployment $deployment, string $email): ?User {
		return $this->rowToUser(
			$this->database->fetch("getByDeploymentAndEmail", $deployment->id, $email),
			$deployment
		);
	}
	/** Retains the public id field and named parameter contract.
	* @SuppressWarnings("PHPMD.ShortVariable")
	*/
	private function getById(string $id): ?User {
		return $this->rowToUser($this->database->fetch("getById", $id));
	}
	public function checkLogin(User $user, string $password): bool {
		$hash = $this->database->fetchString("getHashById", $user->id);
		$success = $hash && password_verify($password, $hash);
		$this->audit->create(
			$success ? Action::PASSWORD_LOGIN_SUCCEEDED : Action::PASSWORD_LOGIN_FAILED,
			["deploymentId" => $user->deployment->id],
			$user
		);
		return $success;
	}
	public function create(
		ApplicationDeployment $deployment,
		string $email,
		?string $password = null
	): User {
		$userId = new Ulid();
		$this->database->insert(
			"create",
			[
				"id" => $userId,
				"applicationDeploymentId" => $deployment->id,
				"email" => $email,
			]
		);
		$user = new User((string) $userId, $deployment, $email);
		$this->audit->create(Action::USER_CREATED, ["deploymentId" => $deployment->id], $user);
		$this->generateAuthCode($deployment, $userId, $password);
		return $user;
	}
	/**
		* Generating a security token will create a new random value to insert
		* into the user_token table. When there's a token in the table, the
		* user will be forced to enter it when they log on. An optional new
		* password can be assigned when the user successfully enters the code.
	* @SuppressWarnings("PHPMD.StaticAccess")
	*/
	public function generateAuthCode(
		ApplicationDeployment $deployment,
		string $userId,
		?string $newPassword = null
	): void {
		$hash = null;
		if ($newPassword) {
			$hash = password_hash($newPassword, PASSWORD_DEFAULT);
		}
		$code = str_pad((string) rand(1000, 99999), 5, "0", STR_PAD_LEFT);
		Log::info("Generated new security code for user {$userId}");
		$user = $this->getById($userId);
		$this->audit->create(
			Action::SECURITY_CODE_REQUESTED,
			["deploymentId" => $deployment->id],
			$user
		);
		$this->database->insert(
			"createAuthCode",
			[
				"id" => new Ulid(),
				"userId" => $userId,
				"code" => $code,
				"hash" => $hash,
			]
		);
		$this->emailer->scheduleAuthCode(
			$user,
			$deployment,
			$user->email,
			$user->deployment->application->name,
			$code,
			$user->deployment->application->emailSendFrom
		);
	}
	public function getLatestAuthCode(string $userId): ?string {
		return $this->database->fetchString("getLatestAuthCode", $userId);
	}
	public function checkAuthCode(User $user, string $authCode): bool {
		$expectedAuthCode = $this->getLatestAuthCode($user->id);
		$success = !is_null($expectedAuthCode) && hash_equals($expectedAuthCode, $authCode);
		$this->audit->create(
			$success ? Action::SECURITY_CODE_ACCEPTED : Action::SECURITY_CODE_REJECTED,
			["deploymentId" => $user->deployment->id],
			$user
		);
		if ($success) {
			$this->consumeAuthCode($user->id, $expectedAuthCode);
		}
		return $success;
	}
	/** Uses stateless utility/framework APIs.
	* @SuppressWarnings("PHPMD.StaticAccess")
	*/
	private function consumeAuthCode(string $userId, ?string $authCode): void {
		if (!$authCode) {
			return;
		}
		$this->database->update("setHashFromAuthCode", $userId, $authCode);
		$this->database->delete("consumeUserAuthToken", $userId, $authCode);
		Log::info("Consumed token {$authCode} for user {$userId}");
	}
	/** Uses stateless utility/framework APIs.
	* @SuppressWarnings("PHPMD.StaticAccess")
	*/
	public function cleanOldAuthCodes(): void {
		$numCleaned = $this->database->delete("deleteOldAuthCodes");
		Log::info("Cleaned {$numCleaned} old tokens");
	}
	private function rowToUser(?Row $row, ?ApplicationDeployment $deployment = null): ?User {
		if (!$row) {
			return null;
		}
		if (!$deployment) {
			$deployment =
				$this->applicationRepo->getDeploymentById($row->getString("applicationDeploymentId"));
		}
		return new User($row->getString("userId"), $deployment, $row->getString("email"));
	}
}
