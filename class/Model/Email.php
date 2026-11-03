<?php
namespace Authwave\Model;

use DateTimeImmutable;

readonly class Email {
	public function __construct(
		public string $id,
		public string $deploymentId,
		public ?DateTimeImmutable $sentAt,
		public ?string $sentMessageId,
		public DateTimeImmutable $createdAt,
		public ?DateTimeImmutable $scheduledToSendAt,
		public string $subject,
		public string $toEmail,
		public string $senderName,
		public string $senderAddress,
		public string $textContent,
		public string $htmlContent,
	) {}
}
