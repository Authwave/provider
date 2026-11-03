<?php

namespace Authwave\Test\Email;

use Authwave\Email\EmailRepository;
use Authwave\Model\EmailSettings;
use Authwave\Security\Audit;
use Gt\Database\Query\QueryCollection;
use Gt\Database\Result\ResultSet;
use Gt\Database\Result\Row;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\TransportInterface;

class EmailRepositoryTest extends TestCase {
	public function testScheduleAuthCode_securityCodeTemplateHasReadableHtmlAndPlainText():void {
		chdir(dirname(__DIR__, 3));
		$db = $this->createMock(QueryCollection::class);
		$db->expects(self::once())->method("insert")->with("schedule", self::callback(function(array $data):bool {
			self::assertSame("Your Example App security code is 01234", $data["subject"]);
			self::assertStringContainsString(">01234</strong>", $data["htmlContent"]);
			self::assertStringContainsString("font-size:40px", $data["htmlContent"]);
			self::assertStringContainsString('src="https://login.example.test/asset/default-logo.svg"', $data["htmlContent"]);
			self::assertStringContainsString("01234", $data["textContent"]);
			self::assertStringContainsString("If you didn't request this code", $data["textContent"]);
			self::assertStringNotContainsString("<", $data["textContent"]);
			self::assertStringNotContainsString("{{", $data["htmlContent"]);
			self::assertStringNotContainsString("style=", $data["textContent"]);
			return true;
		}));
		$audit = $this->createMock(Audit::class);
		$audit->expects(self::once())->method("recordChange")->with(
			\Authwave\Security\Action::EMAIL_SCHEDULED,
			null, self::callback(function(object $detail):bool {
				self::assertSame("recipient@example.test", $detail->recipient);
				self::assertSame("deployment", $detail->deploymentId);
				$json = json_encode($detail, JSON_THROW_ON_ERROR);
				self::assertStringNotContainsString("01234", $json);
				self::assertStringNotContainsString("secret", $json);
				return true;
			}), self::isInstanceOf(\Authwave\Model\User::class));
		$sut = $this->getMockBuilder(EmailRepository::class)
			->setConstructorArgs([$db, new EmailSettings("localhost", 1025, "", ""), $audit])
			->onlyMethods(["sendScheduled"])->getMock();
		$sut->expects(self::once())->method("sendScheduled")->willReturn([]);
		$app = new \Authwave\Model\Application("app", "Example App", "sender@example.test");
		$deployment = new \Authwave\Model\ApplicationDeployment("deployment", $app, "Example App", "secret", "client.example.test", "/", "login.example.test");
		$user = new \Authwave\Model\User("user", $deployment, "recipient@example.test");
		$sut->scheduleAuthCode($user, $deployment, $user->email, $app->name, "01234", $app->emailSendFrom);
	}

	private function repository(bool $ignoreErrors, ?RuntimeException $error = null):EmailRepository {
		$transport = $this->createMock(TransportInterface::class);
		$send = $transport->expects(self::once())->method("send");
		if($error) {
			$send->willThrowException($error);
		}

		$row = new Row([
			"id" => "test-email",
			"emailSettings" => "{}",
			"senderName" => "Authwave",
			"senderAddress" => "sender@example.com",
			"toEmail" => "recipient@example.com",
			"subject" => "Login code",
			"textContent" => "123456",
			"htmlContent" => "<p>123456</p>",
		]);
		$rows = $this->createStub(ResultSet::class);
		$rows->method("valid")->willReturnOnConsecutiveCalls(true, false);
		$rows->method("current")->willReturn($row);
		$db = $this->createMock(QueryCollection::class);
		$db->expects(self::atLeastOnce())->method("fetchAll")->with("getScheduled")->willReturn($rows);
		if($error) {
			$db->expects(self::never())->method("update");
		}
		else {
			$db->expects(self::once())->method("update")->with("markAsSent", self::callback(
				fn(array $data) => $data["id"] === "test-email" && !empty($data["sentMessageId"])
			));
		}

		return new EmailRepository($db,
			new EmailSettings("localhost", 1025, "", ""),
			$this->createStub(Audit::class), new Mailer($transport), $ignoreErrors);
	}

	public function testSendScheduled_developmentContinuesAfterTransportFailure():void {
		$sut = $this->repository(true, new TransportException("SMTP authentication failed"));
		self::assertSame([], $sut->sendScheduled());
	}

	public function testSendScheduled_productionPropagatesTransportFailure():void {
		$sut = $this->repository(false, new TransportException("Connection refused"));
		$this->expectException(TransportException::class);
		$sut->sendScheduled();
	}

	public function testSendScheduled_developmentStillSendsWhenTransportWorks():void {
		$sut = $this->repository(true);
		self::assertSame(["test-email"], $sut->sendScheduled());
	}

	public function testSendScheduled_developmentPropagatesOtherErrors():void {
		$sut = $this->repository(true, new RuntimeException("Unexpected failure"));
		$this->expectException(RuntimeException::class);
		$sut->sendScheduled();
	}
}
