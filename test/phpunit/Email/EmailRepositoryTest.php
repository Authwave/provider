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
use Closure;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\TransportInterface;

class EmailRepositoryTest extends TestCase {
	public function testSendScheduled_invalidRecipientDoesNotBlockSubsequentLoginEmail():void {
		$transport = $this->createMock(TransportInterface::class);
		$recipients = [];
		$transport->expects(self::exactly(2))->method("send")->with(self::callback(function(Email $email) use (&$recipients):bool {
			$recipients[] = $email->getTo()[0]->getAddress();
			self::assertSame("123456", $email->getTextBody());
			return true;
		}));

		$data = [
			"emailSettings" => "{}",
			"clientHost" => "https://client.example.com",
			"emailReplyTo" => null,
			"senderName" => "Authwave",
			"senderAddress" => "sender@example.com",
			"subject" => "Login code",
			"textContent" => "123456",
			"htmlContent" => "<p>123456</p>",
		];

		$invalid = new Row(["id" => "invalid-email", "toEmail" => "test.user.@example.com"] + $data);
		$valid = new Row(["id" => "valid-email", "toEmail" => "recipient@example.com"] + $data);

		$rows = $this->createStub(ResultSet::class);
		$rows->method("valid")->willReturnOnConsecutiveCalls(true, true, false);
		$rows->method("current")->willReturnOnConsecutiveCalls($invalid, $valid);

		$db = $this->createMock(QueryCollection::class);
		$db->expects(self::once())->method("fetchAll")->with("getScheduled")->willReturn($rows);
		$db->expects(self::exactly(2))->method("update")->with("markAsSent", self::callback(
			fn(array $data) => in_array($data["id"], ["invalid-email", "valid-email"], true) && !empty($data["sentMessageId"])
		));
		$sut = new EmailRepository(
			$db,
			new EmailSettings("localhost", 1025, "", ""),
			$this->createStub(Audit::class),
			new Mailer($transport)
		);

		self::assertSame(["invalid-email", "valid-email"], $sut->sendScheduled());
		self::assertSame(["test.user@example.com", "recipient@example.com"], $recipients);
	}

}
