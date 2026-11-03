<?php

namespace Authwave\Test\Security;

use Gt\Database\Database;
use Authwave\Security\Action;
use Authwave\Security\Audit;
use Authwave\Security\AnonUser;
use PHPUnit\Framework\TestCase;
use Authwave\Session\LoginSession;
use Authwave\Model\User;
use Authwave\User\UserRepository;
use Authwave\User\LoginState;
use Authwave\Model\Application;
use Authwave\Model\ApplicationDeployment;
use Gt\Database\Query\QueryCollection;
use Gt\Http\Request;
use Gt\Http\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class AuditTest extends TestCase {
	public function testCreate_eventsWithoutContextStoreANullDetail():void {
		$queries = $this->createMock(\Gt\Database\Query\QueryCollection::class);
		$queries->expects(self::once())->method("insert")->with("create",
			self::callback(function(array $row):bool {
				self::assertNull($row["detail"]);
				self::assertSame("PASSWORD_CHANGED", $row["action"]);
				return true;
			}));
		$sut = new Audit($queries);
		$sut->create(Action::PASSWORD_CHANGED, null, new AnonUser("anonymous"));
	}

	public function testRecordChange_nestedCredentialsAreOmittedAndMutationsAreNotRepeated():void {
		$queries = $this->createMock(\Gt\Database\Query\QueryCollection::class);
		$queries->expects(self::once())->method("insert")->with("create",
			self::callback(function(array $row):bool {
				self::assertSame('{"applicationId":"app","from":{"name":"Old"},"to":{"name":"Café","nested":{"safe":true}}}', $row["detail"]);
				return true;
			}));
		$sut = new Audit($queries);
		$sut->recordChange(Action::APPLICATION_UPDATED,
			(object)["name" => "Old", "same" => 1, "hash" => "old-credential"],
			(object)["name" => "Café", "same" => 1, "hash" => "new-credential",
				"nested" => (object)["token" => "unsafe", "secret" => "unsafe", "safe" => true]],
			new AnonUser("anonymous"), ["applicationId" => "app", "secretIv" => "unsafe"]);
	}

	private function request(string $method = "GET"):Request {
		$request = $this->createStub(Request::class);
		$request->method("getMethod")->willReturn($method);
		$request->method("getUri")->willReturn(new Uri("/admin/users/?password=unsafe&token=unsafe"));

		return $request;
	}

	#[DataProvider("requestMethods")]
	public function testRecordRequest_anonymousRequestsAreConciseAndRecordedOnce(string $method):void {
		$login = $this->createStub(LoginSession::class);
		$login->method("getState")->willReturn(LoginState::NOT_LOGGED_IN);
		$users = $this->createMock(UserRepository::class);
		$users->expects(self::never())->method("get");

		$queries = $this->createMock(QueryCollection::class);
		$queries->expects(self::once())->method("insert")->with("create",
			self::callback(function(array $row)use($method):bool {
				self::assertSame("REQUEST_RECEIVED", $row["action"]);
				self::assertSame(["method" => $method, "path" => "/admin/users/"],
					json_decode($row["detail"], true, flags: JSON_THROW_ON_ERROR));
				self::assertSame("anon1234", $row["anonUserId"]);
				self::assertNull($row["userId"]);

				return true;
			}));

		$sut = new Audit($queries);
		$request = $this->request($method);
		$sut->recordRequest($request, $login, $users, new AnonUser("anon1234"));
		$sut->recordRequest($request, $login, $users, new AnonUser("anon1234"));
	}

	public static function requestMethods():iterable {
		yield ["GET"];
		yield ["POST"];
	}

	#[DataProvider("userAvailability")]
	public function testRecordRequest_authenticatedRequestsResolveTheirActor(bool $userExists):void {
		$deployment = new ApplicationDeployment("deployment",
			new Application("app", "App", "sender@example.test"), "Production", "secret", "app.test", "/");
		$user = new User("user", $deployment, "user@example.test");

		$login = $this->createStub(LoginSession::class);
		$login->method("getState")->willReturn(LoginState::LOGGED_IN);
		$login->method("getDeployment")->willReturn($deployment);
		$login->method("getEmail")->willReturn($user->email);

		$users = $this->createMock(UserRepository::class);
		$users->expects(self::once())->method("get")->with($deployment, $user->email)
			->willReturn($userExists ? $user : null);

		$queries = $this->createMock(QueryCollection::class);
		$queries->expects(self::once())->method("insert")->with("create",
			self::callback(function(array $row)use($userExists):bool {
				self::assertSame($userExists ? "user" : null, $row["userId"]);
				self::assertSame($userExists ? null : "anon1234", $row["anonUserId"]);

				return true;
			}));

		$sut = new Audit($queries);
		$sut->recordRequest($this->request(), $login, $users, new AnonUser("anon1234"));
	}

	public static function userAvailability():iterable {
		yield [true];
		yield [false];
	}

	public function testRecordRequest_failedWritesPropagateAndDoNotMarkTheRequestAsRecorded():void {
		$queries = $this->createMock(QueryCollection::class);
		$queries->expects(self::exactly(2))->method("insert")
			->willThrowException(new RuntimeException("Audit failed"));

		$login = $this->createStub(LoginSession::class);
		$login->method("getState")->willReturn(LoginState::NOT_LOGGED_IN);
		$sut = new Audit($queries);

		for($attempt = 0; $attempt < 2; $attempt++) {
			try {
				$sut->recordRequest($this->request(), $login,
					$this->createStub(UserRepository::class), new AnonUser("anon1234"));
				self::fail("Expected audit failure.");
			}
			catch(RuntimeException $error) {
				self::assertSame("Audit failed", $error->getMessage());
			}
		}
	}

	#[DataProvider("changes")]
	public function testRecordChange_changedValues(?object $before, ?object $after, array $context, ?array $expected):void {
		$queries = $this->createMock(QueryCollection::class);
		$queries->expects(self::once())->method("insert")->with("create",
			self::callback(function(array $row)use($expected):bool {
				$detail = $row["detail"] === null ? null : (array)json_decode($row["detail"], flags: JSON_THROW_ON_ERROR);
				self::assertEquals($expected, $detail);
				return true;
			}));
		$sut = new Audit($queries);

		$sut->recordChange(Action::APPLICATION_UPDATED, $before, $after, null, $context);
	}

	public static function changes():iterable {
		$website = (object)["name" => "Website"];
		yield "creation" => [null, $website, [], ["to" => $website]];
		yield "deletion" => [$website, null, [], ["from" => $website]];
		yield "update" => [(object)["name" => "Old", "same" => 1], (object)["name" => "New", "same" => 1],
			["applicationId" => "app"], ["applicationId" => "app", "from" => (object)["name" => "Old"], "to" => (object)["name" => "New"]]];
		$before = (object)["removed" => null, "number" => 1];
		$after = (object)["added" => null, "number" => "1"];
		yield "null, missing and scalar types" => [$before, $after, [], ["from" => $before, "to" => $after]];
		yield "unchanged" => [$website, $website, [], null];
		yield "event context" => [null, null, ["reason" => "invalid_name"], ["reason" => "invalid_name"]];
		yield "equivalent nested values" => [(object)["nested" => (object)["value" => 1]],
			(object)["nested" => (object)["value" => 1]], [], null];
	}
}
