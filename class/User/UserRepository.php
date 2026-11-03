<?php

namespace Authwave\User;

use Authwave\Email\EmailRepository;
use Authwave\Model\User;
use Authwave\Model\ApplicationDeployment;
use Authwave\Model\ApplicationRepository;
use Authwave\Security\Audit;
use Authwave\Security\Action;
use Gt\Database\Query\QueryCollection;
use Gt\Database\Result\Row;
use Gt\Logger\Log;
use GT\Ulid\Ulid;
readonly class UserRepository {

	public function __construct(
		private QueryCollection $database,
		private ApplicationRepository $applicationRepo,
		private EmailRepository $emailer,
		private Audit $audit
	) {
	}
	public function get(ApplicationDeployment $deployment, string $email):?User {
		$userId = $this->database->fetchString("getIdByDeploymentAndEmail", $deployment->id, $email);

		return $this->getById($userId);
	}

	/** Retains the public id field and named parameter contract.
	* @SuppressWarnings("PHPMD.ShortVariable")
	*/
	public function getById(?string $id):?User {
		if($id === null) {
			return null;
		}

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
		$this->audit->recordChange(
			Action::USER_CREATED,
			before: null,
			after: (object)[
				"userId" => $user->id,
				"deploymentId" => $deployment->id,
				"email" => $email,
			],
			actor: $user,
		);
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
			$user->deployment->application->emailSendFrom ?? EmailRepository::DEFAULT_EMAIL_FROM_ADDRESS
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

		$previousHash = $this->database->fetchString("getHashById", $userId);
		$this->database->update("setHashFromAuthCode", $userId, $authCode);
		$currentHash = $this->database->fetchString("getHashById", $userId);

		if($previousHash !== $currentHash) {
			$this->audit->create(Action::PASSWORD_CHANGED, null, $this->getById($userId));
		}

		$this->database->delete("consumeUserAuthToken", $userId, $authCode);
		Log::info("Consumed security token for user {$userId}");
	}
	/** Uses stateless utility/framework APIs.
	* @SuppressWarnings("PHPMD.StaticAccess")
	*/
	public function cleanOldAuthCodes(): void {
		$numCleaned = $this->database->delete("deleteOldAuthCodes");
		Log::info("Cleaned {$numCleaned} old tokens");
	}
	private function rowToUser(?Row $row): ?User {
		if (!$row) {
			return null;
		}
		$deployment = $this->applicationRepo->getDeploymentById(
			$row->getString("applicationDeploymentId"),
		);

		if($deployment === null) {
			return null;
		}

		return new User($row->getString("userId"), $deployment,
			$row->getString("email"), $row->getString("hash"));
	}
}
