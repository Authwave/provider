<?php
namespace Authwave\Test\Notification;

use Authwave\Model\{User, Application, ApplicationDeployment, ApplicationRepository};
use Authwave\Notification\UserNotifications;
use Authwave\User\UserRepository;
use Authwave\Security\{Action, Audit};
use GT\Cipher\{InitVector, Key};
use GT\Cipher\Message\EncryptedMessage;
use GT\Database\Database;
use GT\Database\Connection\{Connection, Driver};
use GT\Database\Query\QueryCollection;
use GT\Fetch\{Http, FetchException};
use GT\Http\{Response, Uri};
use GT\Http\Header\ResponseHeaders;
use GT\Promise\Deferred;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class UserNotificationsTest extends TestCase {
	#[DataProvider("deliveryOutcomes")]
	public function testSend_waitsForDeliveryAndRecordsStatus(int $status, bool $delivered, bool $acknowledged = true):void {
		$user = $this->user();
		$actor = $this->createStub(User::class);
		$queries = $this->createMock(QueryCollection::class);
		$queries->expects(self::once())->method("update")->with("setClientNotificationStatus", $status, (int)$delivered, "user-created", "new-user")
			->willReturn(1);
		$connection = $this->createMock(Connection::class);
		$connection->expects(self::once())->method("beginTransaction")->willReturn(true);
		$connection->expects(self::once())->method("commit")->willReturn(true);
		$http = $this->createMock(Http::class);
		$deferred = new Deferred();
		$http->expects(self::once())->method("fetch")->with(self::callback(function($uri):bool {
			self::assertSame("https://client.test/login/", strtok((string)$uri, "?"));
			parse_str($uri->getQuery(), $query);
			$vector = (new InitVector())->withBytes(base64_decode($query["AUTHWAVE_NEW_USER_IV"], true));
			$message = new EncryptedMessage($query["AUTHWAVE_NEW_USER"], $vector);
			$data = json_decode((string)$message->decrypt(new Key(str_repeat("s", 32))), flags: JSON_THROW_ON_ERROR);
			self::assertSame("user-created", $data->action);
			self::assertSame("deployment", $data->deploymentId);
			self::assertSame("new-user", $data->id);
			self::assertSame("new@example.test", $data->email);
			self::assertEqualsWithDelta(time(), $data->issuedAt, 2);
			self::assertSame(["action", "deploymentId", "issuedAt", "id", "email"], array_keys((array)$data));
			return true;
		}), ["method" => "GET"])->willReturn($deferred->getPromise());
		$http->expects(self::once())->method("wait")->willReturnCallback(function()use($deferred, $status, $acknowledged):void {
			if($status === 0) {
				$deferred->reject(new FetchException("connection failed"));
				return;
			}

			$headers = new ResponseHeaders($acknowledged ? ["Authwave-User-Change" => "received"] : []);
			$deferred->resolve(new Response($status, $headers));
		});
		$audit = $this->createMock(Audit::class);
		$audit->expects(self::once())->method("create")
			->with($delivered ? Action::USER_NOTIFICATION_DELIVERED : Action::USER_NOTIFICATION_FAILED,
				["userId" => "new-user", "status" => $status, "action" => "user-created"], $actor);
		$sut = new UserNotifications($this->database($queries, $connection), $http, $audit, $this->applications(), $this->createStub(UserRepository::class));

		self::assertSame($delivered, $sut->send($user, $actor));
	}

	public static function deliveryOutcomes():iterable {
		yield [204, true];
		yield "constructor processed notification" => [200, true];
		yield "unhandled HTML page" => [200, false, false];
		yield "missing acknowledgement" => [204, false, false];
		yield [201, true];
		yield [400, false];
		yield [302, false];
		yield [408, false];
		yield [429, false];
		yield [503, false];
		yield [0, false];
	}

	public function testSend_unassignedApplicationCannotReceiveNotifications():void {
		$applications = $this->createStub(ApplicationRepository::class);
		$applications->method("get")->willThrowException(new RuntimeException("No access"));
		$http = $this->createMock(Http::class);
		$http->expects(self::never())->method("fetch");
		$sut = new UserNotifications($this->createStub(Database::class), $http,
			$this->createStub(Audit::class), $applications, $this->createStub(UserRepository::class));
		$this->expectExceptionMessage("No access");

		$sut->send($this->user(), $this->createStub(User::class));
	}

	public function testSend_auditFailureRollsBackTheRecordedStatus():void {
		$connection = $this->createMock(Connection::class);
		$connection->expects(self::once())->method("rollBack")->willReturn(true);
		$connection->expects(self::never())->method("commit");
		$http = $this->createStub(Http::class);
		$deferred = new Deferred();
		$http->method("fetch")->willReturn($deferred->getPromise());
		$http->method("wait")->willReturnCallback(fn() => $deferred->resolve(new Response(200, new ResponseHeaders(["Authwave-User-Change" => "received"]))));
		$audit = $this->createStub(Audit::class);
		$audit->method("create")->willThrowException(new RuntimeException("audit failed"));
		$sut = new UserNotifications($this->database($this->createStub(QueryCollection::class), $connection),
			$http, $audit, $this->applications(), $this->createStub(UserRepository::class));
		$this->expectExceptionMessage("audit failed");

		$sut->send($this->user(), $this->createStub(User::class));
	}

	public function testSendDeletion_allowsDeletingTheActorAfterAcknowledgement():void {
		$user = $this->user();
		$actor = new User($user->id, $user->deployment, $user->email);
		$users = $this->createMock(UserRepository::class);
		$users->expects(self::once())->method("delete")->with($actor, $user);
		$http = $this->createMock(Http::class);
		$deferred = new Deferred();
		$http->expects(self::once())->method("fetch")->with(self::callback(function($uri):bool {
			parse_str($uri->getQuery(), $query);
			$vector = (new InitVector())->withBytes(base64_decode($query["AUTHWAVE_DELETE_USER_IV"]));
			$message = new EncryptedMessage($query["AUTHWAVE_DELETE_USER"], $vector);
			$data = json_decode((string)$message->decrypt(new Key(str_repeat("s", 32))));
			return $data->action === "user-deleted" && $data->id === "new-user";
		}), ["method" => "GET"])->willReturn($deferred->getPromise());
		$http->method("wait")->willReturnCallback(fn() => $deferred->resolve(
			new Response(200, new ResponseHeaders(["Authwave-User-Change" => "received"]))));
		$sut = new UserNotifications($this->createStub(Database::class), $http,
			$this->createStub(Audit::class), $this->applications(), $users);

		self::assertTrue($sut->sendDeletion($user, $actor));
	}

	public function testSendDeletion_failureRetainsTheUserAndStoresTheRetryAction():void {
		$users = $this->createMock(UserRepository::class);
		$users->expects(self::never())->method("delete");
		$queries = $this->createMock(QueryCollection::class);
		$queries->expects(self::once())->method("update")
			->with("setClientNotificationStatus", 503, 0, "user-deleted", "new-user")->willReturn(1);
		$http = $this->createStub(Http::class);
		$deferred = new Deferred();
		$http->method("fetch")->willReturn($deferred->getPromise());
		$http->method("wait")->willReturnCallback(fn() => $deferred->resolve(new Response(503)));
		$audit = $this->createMock(Audit::class);
		$audit->expects(self::once())->method("create")->with(Action::USER_NOTIFICATION_FAILED,
			["userId" => "new-user", "status" => 503, "action" => "user-deleted"], self::anything());
		$sut = new UserNotifications($this->database($queries, $this->createStub(Connection::class)),
			$http, $audit, $this->applications(), $users);

		self::assertFalse($sut->sendDeletion($this->user(), new User("administrator", $this->user()->deployment, "admin@example.test")));
	}

	public function testSend_retriesThePendingDeletionInsteadOfCreatingTheUser():void {
		$original = $this->user();
		$user = new User($original->id, $original->deployment, $original->email,
			clientNotificationStatus: 503, clientNotificationAcknowledged: false,
			clientNotificationAction: "user-deleted");
		$actor = new User("administrator", $user->deployment, "admin@example.test");
		$users = $this->createMock(UserRepository::class);
		$users->expects(self::once())->method("delete")->with($actor, $user);
		$http = $this->createMock(Http::class);
		$deferred = new Deferred();
		$http->expects(self::once())->method("fetch")->with(self::callback(function($uri):bool {
			parse_str($uri->getQuery(), $query);
			return isset($query["AUTHWAVE_DELETE_USER"], $query["AUTHWAVE_DELETE_USER_IV"])
				&& !isset($query["AUTHWAVE_NEW_USER"]);
		}), ["method" => "GET"])->willReturn($deferred->getPromise());
		$http->method("wait")->willReturnCallback(fn() => $deferred->resolve(
			new Response(204, new ResponseHeaders(["Authwave-User-Change" => "received"]))));
		$sut = new UserNotifications($this->createStub(Database::class), $http,
			$this->createStub(Audit::class), $this->applications(), $users);

		self::assertTrue($sut->send($user, $actor));
	}

	private function user():User {
		return new User("new-user", new ApplicationDeployment("deployment", new Application("app", "App", null),
			"Development", str_repeat("s", 32), "https://client.test", "/login/"), "new@example.test");
	}

	private function applications():ApplicationRepository {
		$applications = $this->createStub(ApplicationRepository::class);
		$applications->method("get")->willReturn(new Application("app", "App", null));
		$applications->method("getClientRedirectUri")->willReturn(new Uri("https://client.test/login/"));
		return $applications;
	}

	private function database(QueryCollection $queries, Connection $connection):Database {
		$driver = $this->createStub(Driver::class);
		$driver->method("getConnection")->willReturn($connection);
		$database = $this->createStub(Database::class);
		$database->method("getDriver")->willReturn($driver);
		$database->method("queryCollection")->willReturn($queries);
		return $database;
	}
}
