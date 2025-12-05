<?php
namespace Authwave\Email;

use Authwave\Model\ApplicationDeployment;
use Authwave\Model\EmailSettings;
use DateTime;
use DateTimeInterface;
use Gt\Database\Query\QueryCollection;
use Gt\Logger\Log;
use Gt\Ulid\Ulid;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class EmailRepository {
	const DEFAULT_EMAIL_FROM_ADDRESS = "support@authwave.com";
	const DEFAULT_EMAIL_FROM_NAME = "Authwave";

	public function __construct(
		private readonly QueryCollection $db,
		private readonly EmailSettings $defaultEmailSettings,
		private readonly ?Mailer $mailer = null,
	) {}

	public function schedule(
		ApplicationDeployment $deployment,
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
			"deploymentId" => $deployment->id,
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
		ApplicationDeployment $deployment,
		string $email,
		string $siteName,
		string $code,
		string $fromEmail,
	):string {
		return $this->schedule(
			$deployment,
			$email,
			"authCode",
			[
				"code" => $code,
				"siteName" => $siteName,
			],
			$fromEmail,
			$siteName,
		);
	}

	/** @return array<string> */
	public function sendScheduled():array {
		$sentEmailIdList = [];

		foreach($this->db->fetchAll("getScheduled") as $row) {
			$emailSettings = $this->defaultEmailSettings;

			if($emailSettingsObj = json_decode($row->getString("emailSettings"), true)) {
				$emailSettings = new EmailSettings(
					$emailSettingsObj["host"] ?? $emailSettings->host,
					$emailSettingsObj["port"] ?? $emailSettings->port,
					$emailSettingsObj["username"] ?? $emailSettings->username,
					$emailSettingsObj["password"] ?? $emailSettings->password,
				);
			}

			$sentMessageId = $this->send(
				$row->getString("senderName"),
				$row->getString("senderAddress"),
				$row->getString("toEmail"),
				$row->getString("subject"),
				$row->getString("textContent"),
				$row->getString("htmlContent"),
				$emailSettings,
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
		EmailSettings $emailSettings,
	):string {
		$transport = Transport::fromDsn(implode("", [
			"smtp://",
			$emailSettings->username,
			":",
			$emailSettings->password,
			"@",
			$emailSettings->host,
			":",
			$emailSettings->port,
		]));

		$mailer = $this->mailer ?? new Mailer($transport);
		$email = new Email();
		$email->addFrom(new Address($fromAddress, $fromName));
		$email->addTo(new Address($toAddress));
		$email->subject($subject);
		$email->text($markdown);
		$email->html($html);

		$ulid = new Ulid("EMAIL");
		$headers = $email->getHeaders();
		$headers->addIdHeader("Message-ID", "$ulid@authwave.com");
		$authwaveVersion = shell_exec("git rev-parse HEAD") ?? "unknown";
		$authwaveVersion = trim($authwaveVersion);
		$headers->addHeader("X-AUTHWAVE-VERSION", $authwaveVersion);

		$mailer->send($email);
		return $ulid;
	}
}
