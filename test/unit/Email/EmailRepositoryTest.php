<?php
namespace Authwave\Test\Email;

use Authwave\Email\EmailRepository;
use Authwave\Model\EmailSettings;
use Authwave\Security\Audit;
use GT\Database\Query\QueryCollection;
use GT\Database\Result\ResultSet;
use GT\Database\Result\Row;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\TransportInterface;

class EmailRepositoryTest extends TestCase {
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
		$rows = $this->createMock(ResultSet::class);
		$rows->method("valid")->willReturnOnConsecutiveCalls(true, false);
		$rows->method("current")->willReturn($row);
		$db = $this->createMock(QueryCollection::class);
		$db->method("fetchAll")->with("getScheduled")->willReturn($rows);
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
			$this->createMock(Audit::class), new Mailer($transport), $ignoreErrors);
	}

	public function testDevelopmentContinuesAfterTransportFailure():void {
		$repository = $this->repository(true, new TransportException("SMTP authentication failed"));
		self::assertSame([], $repository->sendScheduled());
	}

	public function testProductionPropagatesTransportFailure():void {
		$repository = $this->repository(false, new TransportException("Connection refused"));
		$this->expectException(TransportException::class);
		$repository->sendScheduled();
	}

	public function testDevelopmentStillSendsWhenTransportWorks():void {
		self::assertSame(["test-email"], $this->repository(true)->sendScheduled());
	}

	public function testDevelopmentPropagatesOtherErrors():void {
		$repository = $this->repository(true, new RuntimeException("Unexpected failure"));
		$this->expectException(RuntimeException::class);
		$repository->sendScheduled();
	}
}
