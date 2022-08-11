<?php
namespace Authwave\User;

use Authwave\Email\Emailer;
use Authwave\Site\Site;
use Authwave\Site\SiteRepository;
use Gt\Database\Query\QueryCollection;
use Gt\Database\Result\Row;
use Gt\Logger\Log;
use Gt\Ulid\Ulid;

class UserRepository {
	public function __construct(
		private readonly QueryCollection $db,
		private readonly SiteRepository $siteRepo,
		private readonly Emailer $emailer,
	) {}

	public function get(Site $site, string $email):?User {
		return $this->rowToUser(
			$this->db->fetch(
				"getBySiteAndEmail",
				$site->id,
				$email,
			),
			$site,
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
		Site $site,
		string $email,
		string $password,
	):void {
		$userId = new Ulid();
		$this->db->insert("create", [
			"id" => $userId,
			"siteId" => $site->id,
			"email" => $email,
		]);
		$this->generateSecurityToken($userId, $password);
	}

	/**
	 * Generating a security token will create a new random value to insert
	 * into the user_token table. When there's a token in the table, the
	 * user will be forced to enter it when they log on. An optional new
	 * password can be assigned when the user successfully enters the code.
	 */
	public function generateSecurityToken(
		string $userId,
		string $newPassword = null,
	):void {
		$hash = null;
		if($newPassword) {
			$hash = password_hash($newPassword, PASSWORD_DEFAULT);
		}

		$token = str_pad(
			(string)rand(1_000, 99_999),
			5,
			"0",
			STR_PAD_LEFT
		);

		Log::info("Generated new token for user $userId");

		$this->db->insert("createToken", [
			"id" => new Ulid(),
			"userId" => $userId,
			"token" => $token,
			"hash" => $hash,
		]);

		$user = $this->getById($userId);
		$this->emailer->sendToken(
			$user->email,
			$user->site->name,
			$token,
		);
	}

	public function getLatestSecurityToken(string $userId):?string {
		return $this->db->fetchString("getLatestToken", $userId);
	}

	public function consumeToken(string $userId, ?string $token):void {
		if(!$token) {
			return;
		}

		$this->db->update("setPasswordFromToken", $userId, $token);
		$this->db->delete("deleteToken", $userId, $token);
		Log::info("Consumed token $token for user $userId");
	}

	public function cleanOldTokens():void {
		$numCleaned = $this->db->delete("deleteOldTokens");
		Log::info("Cleaned $numCleaned old tokens");
	}

	private function rowToUser(?Row $row, ?Site $site = null):?User {
		if(!$row) {
			return null;
		}

		if(!$site) {
			$site = $this->siteRepo->getById($row->getString("siteId"));
		}

		return new User(
			$row->getString("id"),
			$site,
			$row->getString("email"),
		);
	}
}
