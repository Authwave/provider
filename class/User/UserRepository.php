<?php
namespace Authwave\User;

use Authwave\Email\EmailRepository;
use Authwave\Model\ApplicationDeployment;
use Authwave\Model\ApplicationRepository;
use Gt\Database\Query\QueryCollection;
use Gt\Database\Result\Row;
use Gt\Logger\Log;
use Gt\Ulid\Ulid;

class UserRepository {
	public function __construct(
		private readonly QueryCollection $db,
		private readonly ApplicationRepository $applicationRepo,
		private readonly EmailRepository $emailer,
	) {}

	public function get(
		ApplicationDeployment $deployment,
		string $email,
	):?User {
		return $this->rowToUser(
			$this->db->fetch(
				"getByDeploymentAndEmail",
				$deployment->id,
				$email,
			),
			$deployment,
		);
	}

	private function getById(string $id):?User {
		return $this->rowToUser($this->db->fetch("getById", $id));
	}

	public function checkLogin(User $user, string $password):bool {
		$hash = $this->db->fetchString("getHashById", $user->id);
		if(!$hash) {
			return false;
		}

		return password_verify($password, $hash);
	}

	public function create(
		ApplicationDeployment $deployment,
		string $email,
		string $password,
	):void {
		$userId = new Ulid();
		$this->db->insert("create", [
			"id" => $userId,
			"applicationDeploymentId" => $deployment->id,
			"email" => $email,
		]);
		$this->generateAuthCode($userId, $password);
	}

	/**
	 * Generating a security token will create a new random value to insert
	 * into the user_token table. When there's a token in the table, the
	 * user will be forced to enter it when they log on. An optional new
	 * password can be assigned when the user successfully enters the code.
	 */
	public function generateAuthCode(
		string $userId,
		string $newPassword = null,
	):void {
		$hash = null;
		if($newPassword) {
			$hash = password_hash($newPassword, PASSWORD_DEFAULT);
		}

		$code = str_pad(
			(string)rand(1_000, 99_999),
			5,
			"0",
			STR_PAD_LEFT
		);

		Log::info("Generated new auth code for user $userId");

		$this->db->insert("createAuthCode", [
			"id" => new Ulid(),
			"userId" => $userId,
			"code" => $code,
			"hash" => $hash,
		]);

		$user = $this->getById($userId);
		$this->emailer->scheduleAuthCode(
			$user->email,
			$user->deployment->application->name,
			$code,
		);
	}

	public function getLatestAuthCode(string $userId):?string {
		return $this->db->fetchString("getLatestAuthCode", $userId);
	}

	public function consumeAuthCode(string $userId, ?string $authCode):void {
		if(!$authCode) {
			return;
		}

		$this->db->update("setHashFromAuthCode", $userId, $authCode);
		$this->db->delete("consumeUserAuthToken", $userId, $authCode);
		Log::info("Consumed token $authCode for user $userId");
	}

	public function cleanOldAuthCodes():void {
		$numCleaned = $this->db->delete("deleteOldAuthCodes");
		Log::info("Cleaned $numCleaned old tokens");
	}

	private function rowToUser(?Row $row, ?ApplicationDeployment $deployment = null):?User {
		if(!$row) {
			return null;
		}

		if(!$deployment) {
			$deployment = $this->applicationRepo->getDeploymentById($row->getString("applicationDeploymentId"));
		}

		return new User(
			$row->getString("userId"),
			$deployment,
			$row->getString("email"),
		);
	}
}
