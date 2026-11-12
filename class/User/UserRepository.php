<?php

namespace Authwave\User;

use Authwave\Email\EmailRepository;
use Authwave\Model\User;
use Authwave\Model\UserSession;
use Authwave\Model\ApplicationDeployment;
use Authwave\Model\ApplicationRepository;
use Authwave\Security\Audit;
use Authwave\Security\Action;
use GT\Database\Query\QueryCollection;
use GT\Database\Database;
use Authwave\Admin\User\UserActivity;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;
use GT\Database\Result\Row;
use GT\Logger\Log;
use GT\Ulid\Ulid;
readonly class UserRepository {
	const PAGE_SIZE = 25;
	// Estimated client-session lifetime in seconds, measured from login.
	const DEFAULT_SESSION_DURATION = 30 * 60;
	const SESSION_COOKIE_PREFIX = "authwave_session_";

	private QueryCollection $queries;

	public function __construct(
		private Database $database,
		private ApplicationRepository $applicationRepo,
		private EmailRepository $emailer,
		private Audit $audit,
		private int $sessionDuration = self::DEFAULT_SESSION_DURATION,
	) {
		if($sessionDuration < 1) {
			throw new InvalidArgumentException("Client session duration must be positive.");
		}

		$this->queries = $database->queryCollection("user");
	}

	public function get(ApplicationDeployment $deployment, string $email):?User {
		$userId = $this->queries->fetchString("getIdByDeploymentAndEmail", $deployment->id, $email);

		return $this->getById($userId);
	}

	/** Retains the public id field and named parameter contract.
	* @SuppressWarnings("PHPMD.ShortVariable")
	*/
	public function getById(?string $id):?User {
		if($id === null) {
			return null;
		}

		return $this->rowToUser($this->queries->fetch("getById", $id));
	}

	public function checkLogin(User $user, string $password): bool {
		$hash = $this->queries->fetchString("getHashById", $user->id);
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
		$this->queries->insert(
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
		$this->queries->insert(
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
			$user->deployment->getSenderAddress() ?? EmailRepository::DEFAULT_EMAIL_FROM_ADDRESS
		);
	}

	/** @return list<UserActivity> */
	public function search(User $actor, string $applicationId, string $search = "",
		string $deploymentId = "", string $sort = "email", int $page = 1, ?string $userId = null):array {
		$this->applicationRepo->get($actor, $applicationId);
		$this->checkSearchOptions($sort, $page);

		return $this->activity($actor, $applicationId, $search, $deploymentId, $sort,
			self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE, userId: $userId);
	}

	public function countSearch(User $actor, string $applicationId, string $search = "",
		string $deploymentId = ""):int {
		$this->applicationRepo->get($actor, $applicationId);

		return $this->queries->fetchInt("countSearch", $actor->id, $applicationId,
			$this->searchPattern($search), $deploymentId, $deploymentId);
	}

	/** @return list<UserActivity> */
	public function latestFailures(User $actor, string $applicationId):array {
		$this->applicationRepo->get($actor, $applicationId);

		return $this->activity($actor, $applicationId, "", "", "failures", 10, 0,
			new DateTimeImmutable("-24 hours"));
	}

	private function checkSearchOptions(string $sort, int $page):void {
		if(!in_array($sort, ["email", "created", "logins"], true)
		|| $page < 1 || $page > intdiv(PHP_INT_MAX, self::PAGE_SIZE)) {
			throw new InvalidArgumentException("Choose valid user filters and a positive page number.");
		}
	}

	private function searchPattern(string $search):string {
		$search = trim($search);
		$pattern = str_replace(["!", "%", "_", "*"], ["!!", "!%", "!_", "%"], $search);

		return str_contains($search, "*") ? $pattern : "%" . $pattern . "%";
	}

	/** @return list<UserActivity> */
	private function activity(User $actor, string $applicationId, string $search, string $deploymentId,
		string $sort, int $limit, int $offset, ?DateTimeImmutable $since = null,
		?string $userId = null):array {
		$rows = $this->queries->fetchAll("search", [
			"actorId" => $actor->id,
			"applicationId" => $applicationId,
			"userFilter" => $userId,
			"userId" => $userId,
			"search" => $this->searchPattern($search),
			"deploymentFilter" => $deploymentId,
			"deploymentId" => $deploymentId,
			"failureFilter" => $since,
			"since" => $since,
			"emailSort" => $sort,
			"createdSort" => $sort,
			"loginSort" => $sort,
			"failureSort" => $sort,
			"limit" => $limit,
			"offset" => $offset,
		]);
		$result = [];

		foreach($rows as $row) {
			$user = $this->rowToUser($row);

			if($user) {
				$login = $row->getString("lastLoginAt");
				$failure = $row->getString("lastFailureAt");
				$result []= new UserActivity($user, $row->getInt("loginCount"),
					$login === null ? null : new DateTimeImmutable($login),
					$failure === null ? null : new DateTimeImmutable($failure));
			}
		}

		return $result;
	}

	public function createForAdministrator(User $actor, string $applicationId, string $deploymentId,
		string $email, ?string $password, bool $emailInstructions):User {
		$this->applicationRepo->get($actor, $applicationId);
		$deployment = $this->applicationRepo->getDeploymentById($deploymentId);

		if(!$deployment || $deployment->applicationId !== $applicationId) {
			throw new InvalidArgumentException("Choose a deployment belonging to this application.");
		}

		$email = strtolower(trim($email));
		$connection = $this->database->getDriver()->getConnection();
		$connection->beginTransaction();

		try {
			if($this->get($deployment, $email)) {
				throw new InvalidArgumentException(
					"A user with this email address already exists in this deployment.");
			}

			$id = (string)new Ulid();
			$hash = $password === null ? null : password_hash($password, PASSWORD_DEFAULT);
			$this->queries->insert("createByAdministrator", $id, $deploymentId, $email, $hash);
			$user = $this->getById($id);
			$this->audit->recordChange(Action::USER_CREATED, null,
				(object)["userId" => $id, "deploymentId" => $deploymentId, "email" => $email], $actor);

			if($emailInstructions) {
				$this->emailer->queueLoginInstructions($actor, $user);
			}

			$connection->commit();
			return $user;
		}
		catch(Throwable $error) {
			$connection->rollBack();
			throw $error;
		}
	}

	/** @return list<DateTimeImmutable> */
	public function recentLogins(User $actor, User $user):array {
		$this->applicationRepo->get($actor, $user->deployment->applicationId);
		$logins = [];

		foreach($this->queries->fetchAll("recentLogins", $user->id) as $row) {
			$logins []= new DateTimeImmutable($row->getString("createdAt"));
		}

		return $logins;
	}

	/** @return list<ApplicationDeployment> */
	public function deploymentsForEmail(User $actor, User $user):array {
		$this->applicationRepo->get($actor, $user->deployment->applicationId);
		$deployments = [];

		foreach($this->queries->fetchAll("getDeploymentIdsByEmail", $user->deployment->applicationId,
			$user->email) as $row) {
			$deployment = $this->applicationRepo->getDeploymentById($row->getString("id"));

			if($deployment !== null) {
				$deployments []= $deployment;
			}
		}

		return $deployments;
	}

	public function delete(User $actor, User $user):void {
		$this->applicationRepo->get($actor, $user->deployment->applicationId);

		$connection = $this->database->getDriver()->getConnection();
		$connection->beginTransaction();

		try {
			$this->audit->recordChange(Action::USER_DELETED,
				(object)["id" => $user->id, "deploymentId" => $user->deployment->id,
					"email" => $user->email], null, $actor);

			if(!$this->queries->delete("delete", $user->id)) {
				$connection->rollBack();
				return;
			}

			$connection->commit();
		}
		catch(Throwable $error) {
			$connection->rollBack();
			throw $error;
		}
	}

	public function startSession(User $user, ?string $previousId = null):UserSession {
		$previous = $previousId === null ? null : $this->getSessionById($previousId);
		$now = new DateTimeImmutable();

		if($previous && $previous->userId === $user->id
		&& $previous->endedAt === null && $previous->expiresAt > $now) {
			return $previous;
		}

		$connection = $this->database->getDriver()->getConnection();
		$connection->beginTransaction();

		try {
			if($previous && $previous->endedAt === null) {
				$previousUser = $this->getById($previous->userId);

				if($previousUser?->deployment->id === $user->deployment->id) {
					$this->closeSession($previous, $previousUser);
				}
			}

			$session = new UserSession((string)new Ulid(), $user->id, $now,
				$now->modify("+$this->sessionDuration seconds"));
			$this->database->queryCollection("user_session")->insert("create", [
				"id" => $session->id, "userId" => $user->id,
				"createdAt" => $session->createdAt, "expiresAt" => $session->expiresAt,
			]);
			$this->audit->create(Action::LOGIN_COMPLETED,
				["deploymentId" => $user->deployment->id, "sessionId" => $session->id], $user);
			$connection->commit();
			return $session;
		}
		catch(Throwable $error) {
			$connection->rollBack();
			throw $error;
		}
	}

	public function endSession(ApplicationDeployment $deployment, ?string $sessionId):bool {
		$session = $sessionId === null ? null : $this->getSessionById($sessionId);

		if(!$session || $session->endedAt !== null) {
			return false;
		}

		$user = $this->getById($session->userId);

		if($user?->deployment->id !== $deployment->id) {
			return false;
		}

		$connection = $this->database->getDriver()->getConnection();
		$connection->beginTransaction();

		try {
			$this->closeSession($session, $user);
			$connection->commit();
			return true;
		}
		catch(Throwable $error) {
			$connection->rollBack();
			throw $error;
		}
	}

	private function closeSession(UserSession $session, User $user):void {
		$this->database->queryCollection("user_session")->update("end", [
			"id" => $session->id, "endedAt" => new DateTimeImmutable(),
		]);
		$this->audit->create(Action::LOGOUT_REQUESTED,
			["deploymentId" => $user->deployment->id, "sessionId" => $session->id], $user);
	}

	/** @return list<UserSession> */
	public function currentlyLoggedIn(User $actor, string $applicationId):array {
		$this->applicationRepo->get($actor, $applicationId);
		$result = [];

		$now = (new DateTimeImmutable())->format("Y-m-d H:i:s");

		$rows = $this->queries->fetchAll("currentlyLoggedIn", $actor->id, $applicationId, $now);

		foreach($rows as $row) {
			$result []= $this->rowToSession($row);
		}

		return $result;
	}

	private function getSessionById(string $sessionId):?UserSession {
		$row = $this->database->queryCollection("user_session")->fetch("getById", $sessionId);
		return $row ? $this->rowToSession($row) : null;
	}

	private function rowToSession(Row $row):UserSession {
		$ended = $row->getString("endedAt");

		return new UserSession($row->getString("id"), $row->getString("userId"),
			new DateTimeImmutable($row->getString("createdAt")),
			new DateTimeImmutable($row->getString("expiresAt")),
			$ended === null ? null : new DateTimeImmutable($ended));
	}

	public function getLatestAuthCode(string $userId): ?string {
		return $this->queries->fetchString("getLatestAuthCode", $userId);
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

		$previousHash = $this->queries->fetchString("getHashById", $userId);
		$this->queries->update("setHashFromAuthCode", $userId, $authCode);
		$currentHash = $this->queries->fetchString("getHashById", $userId);

		if($previousHash !== $currentHash) {
			$this->audit->create(Action::PASSWORD_CHANGED, null, $this->getById($userId));
		}

		$this->queries->delete("consumeUserAuthToken", $userId, $authCode);
		Log::info("Consumed security token for user {$userId}");
	}

	/** Uses stateless utility/framework APIs.
	* @SuppressWarnings("PHPMD.StaticAccess")
	*/
	public function cleanOldAuthCodes(): void {
		$numCleaned = $this->queries->delete("deleteOldAuthCodes");
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

		$created = $row->getString("createdAt");

		return new User($row->getString("userId"), $deployment,
			$row->getString("email"), $row->getString("hash"),
			$created === null ? null : new DateTimeImmutable($created),
			$row->getInt("clientNotificationStatus"), $row->getBool("clientNotificationAcknowledged"),
			$row->getString("clientNotificationAction"));
	}
}
