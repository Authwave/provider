<?php
namespace Authwave\Email;

use DateTime;
use DateTimeInterface;
use Gt\Database\Query\QueryCollection;
use Gt\Logger\Log;
use Gt\Ulid\Ulid;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;

class EmailRepository {
	const DEFAULT_EMAIL_FROM_ADDRESS = "support@authwave.com";
	const DEFAULT_EMAIL_FROM_NAME = "Authwave";

	public function __construct(
		private readonly QueryCollection $db,
		private readonly string $apiKey,
	) {}

	public function schedule(
		string $toAddress,
		string $templateName,
		array $kvp = [],
		string $fromAddress = self::DEFAULT_EMAIL_FROM_ADDRESS,
		string $fromName = self::DEFAULT_EMAIL_FROM_NAME,
		DateTimeInterface $when = null,
	):string {
		$filePath = "data/email/$templateName.md";
		if(!is_file($filePath)) {
			throw new EmailTemplateNotFoundException($templateName);
		}

		if(!$when) {
			$when = new DateTime();
		}

		$markdown = file_get_contents($filePath);
		$markdown = trim($markdown);

		foreach($kvp as $key => $value) {
			if(!is_scalar($value)) {
				continue;
			}

			$markdown = str_replace(
				"{{" . $key . "}}",
				$value,
				$markdown
			);
		}

		$subject = trim(substr($markdown, 1, strpos($markdown, "\n")));
		$markdown = substr($markdown, strpos($markdown, "\n") + 2);

		$environment = new Environment();
		$environment->addExtension(new AutolinkExtension());
		$environment->addExtension(new AttributesExtension());

		$converter = new CommonMarkConverter();
		$html = $converter->convert($markdown);

		$emailId = new Ulid();
		$this->db->insert("schedule", [
			"id" => $emailId,
			"scheduledToSendAt" => $when,
			"subject" => $subject,
			"toEmail" => $toAddress,
			"senderName" => $fromName,
			"senderAddress" => $fromAddress,
			"textContent" => $markdown,
			"htmlContent" => (string)$html,
		]);

// TODO: Move this to a background task.
		$this->sendScheduled();

		return $emailId;
	}

	public function scheduleAuthCode(
		string $email,
		string $siteName,
		string $code,
	):string {
		return $this->schedule(
			$email,
			"authCode",
			[
				"code" => $code,
				"siteName" => $siteName,
			],
		);
	}

	/** @return array<string> */
	public function sendScheduled():array {
		$sentEmailIdList = [];

		foreach($this->db->fetchAll("getScheduled") as $row) {
			$sentMessageId = $this->send(
				$row->getString("senderName"),
				$row->getString("senderAddress"),
				$row->getString("toEmail"),
				$row->getString("subject"),
				$row->getString("textContent"),
				$row->getString("htmlContent"),
			);

			$this->db->update("markAsSent", [
				"id" => $row->getString("id"),
				"sentMessageId" => $sentMessageId,
			]);

			array_push($sentEmailIdList, $row->getString("id"));
		}

		return $sentEmailIdList;
	}

	public function send(
		string $fromName,
		string $fromAddress,
		string $toAddress,
		string $subject,
		string $markdown,
		string $html,
	):string {
		$emailData = [
			"sender" => [
				"name" => $fromName,
				"email" => $fromAddress,
			],
			"to" => [
				[
					"email" => $toAddress,
				]
			],
			"subject" => $subject,
			"textContent" => $markdown,
			"htmlContent" => $html,
		];

		if($this->apiKey) {
// TODO: Upgrade to use fetch()
			$ch = curl_init("https://api.brevo.com/v3/smtp/email");
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				"accept: application/json",
				"api-key: $this->apiKey",
				"content-type: application/json",
			]);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
			curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);

			$response = curl_exec($ch);
			$emailId = trim($response);
			Log::info("Sent email: $subject ($emailId)");
		}
		else {
			$emailId = "NO API KEY";
			Log::info("Marked email as sent: $subject ($emailId)");
		}

		return trim($emailId);
	}
}
