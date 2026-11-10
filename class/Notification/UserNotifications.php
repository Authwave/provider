<?php
namespace Authwave\Notification;

use Authwave\Model\{User, ApplicationRepository};
use Authwave\Security\{Action, Audit};
use Authwave\User\UserRepository;
use InvalidArgumentException;
use GT\Cipher\{InitVector, Key};
use GT\Cipher\Message\PlainTextMessage;
use GT\Database\Database;
use GT\Fetch\{Http, FetchException};
use GT\Curl\CurlException;
use GT\Http\Response;
use Throwable;

readonly class UserNotifications {
	const NEW_USER_QUERY_PARAMETER = "AUTHWAVE_NEW_USER";
	const NEW_USER_IV_QUERY_PARAMETER = "AUTHWAVE_NEW_USER_IV";
	const DELETE_USER_QUERY_PARAMETER = "AUTHWAVE_DELETE_USER";
	const DELETE_USER_IV_QUERY_PARAMETER = "AUTHWAVE_DELETE_USER_IV";

	public function __construct(private Database $database, private Http $http,
		private Audit $audit, private ApplicationRepository $applications,
		private UserRepository $users) {}

	public function send(User $user, User $actor):bool {
		return $this->sendChange($user, $actor, $user->clientNotificationAction ?? "user-created");
	}

	public function sendDeletion(User $user, User $actor):bool {
		return $this->sendChange($user, $actor, "user-deleted");
	}

	private function sendChange(User $user, User $actor, string $action):bool {
		if(!in_array($action, ["user-created", "user-deleted"], true)) {
			throw new InvalidArgumentException("Unsupported user notification action.");
		}

		$this->applications->get($actor, $user->deployment->applicationId);

		$response = $this->deliver($user, $action);
		$status = $response?->getStatusCode() ?? 0;
		$delivered = $status >= 200 && $status < 300
			&& $response->getHeaderLine("Authwave-User-Change") === "received";

		if($delivered && $action === "user-deleted") {
			$this->users->delete($actor, $user);
			return true;
		}

		$connection = $this->database->getDriver()->getConnection();
		$connection->beginTransaction();

		try {
			$this->database->queryCollection("user")
				->update("setClientNotificationStatus", $status, (int)$delivered, $action, $user->id);
			$event = $delivered ? Action::USER_NOTIFICATION_DELIVERED : Action::USER_NOTIFICATION_FAILED;
			$this->audit->create($event,
				["userId" => $user->id, "status" => $status, "action" => $action], $actor);
			$connection->commit();
		}
		catch(Throwable $error) {
			$connection->rollBack();
			throw $error;
		}

		return $delivered;
	}

	private function deliver(User $user, string $action):?Response {
		$vector = new InitVector();
		$message = new PlainTextMessage(json_encode([
			"action" => $action, "deploymentId" => $user->deployment->id,
			"issuedAt" => time(), "id" => $user->id, "email" => $user->email,
		], JSON_THROW_ON_ERROR), $vector);
		$cipher = (string)$message->encrypt(new Key($user->deployment->secret));
		$response = null;
		$failure = null;

		$queryKey = self::NEW_USER_QUERY_PARAMETER;
		$vectorKey = self::NEW_USER_IV_QUERY_PARAMETER;

		if($action === "user-deleted") {
			$queryKey = self::DELETE_USER_QUERY_PARAMETER;
			$vectorKey = self::DELETE_USER_IV_QUERY_PARAMETER;
		}

		try {
			$uri = $this->applications->getClientRedirectUri($user->deployment)
				->withQuery(http_build_query([
					$queryKey => $cipher,
					$vectorKey => (string)$vector,
				], encoding_type: PHP_QUERY_RFC3986));
			$this->http->fetch($uri, [
				"method" => "GET",
			])->then(function(Response $received)use(&$response):void {
				$response = $received;
			})->catch(function(Throwable $error)use(&$failure):void {
				$failure = $error;
			});
			$this->http->wait();
		}
		catch(CurlException|FetchException) {
			return null;
		}

		if($failure && !$failure instanceof CurlException && !$failure instanceof FetchException) {
			throw $failure;
		}

		return $response;
	}
}
