<?php
namespace Authwave\Email;

use Authwave\Model\ApplicationDeployment;
use Authwave\Admin\Dashboard\ReportingPeriod;
use InvalidArgumentException;
use Authwave\Model\EmailSettings;
use Authwave\Security\Audit;
use Authwave\Security\Action;
use Authwave\Security\AnonUser;
use Authwave\Model\User;
use DateTime;
use DateTimeInterface;
use GT\Database\Query\QueryCollection;
use GT\Logger\Log;
use GT\Ulid\Ulid;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

readonly class EmailRepository {
	const DEFAULT_EMAIL_FROM_ADDRESS = "support@authwave.com";
	const DEFAULT_EMAIL_FROM_NAME = "Authwave";

	public function __construct(
		private QueryCollection $database,
		private EmailSettings $defaultEmailSettings,
		private Audit $audit,
		private ?Mailer $mailer = null,
		private bool $ignoreTransportErrors = false,
		private ?EmailTemplateRepository $templates = null,
	) {}

	public function log(User $actor, string $applicationId, ReportingPeriod $period,
		string $deploymentId = "", string $toEmail = "", string $status = "", int $page = 1,
		string $subject = ""):EmailLog {
		if(!in_array($status, ["", "pending", "sent", "error"], true)
		|| $page < 1 || $page > intdiv(PHP_INT_MAX, 25)
		|| strlen($toEmail) > 256 || strlen($subject) > 256) {
			throw new InvalidArgumentException("Choose valid email filters and page.");
		}

		$rows = $this->database->fetchAll("log", [
			"actorId" => $actor->id, "applicationId" => $applicationId,
			"start" => $period->start, "end" => $period->end,
			"deploymentFilter" => $deploymentId, "deploymentId" => $deploymentId,
			"recipientFilter" => $toEmail, "toEmail" => $toEmail,
			"subjectFilter" => $subject, "subject" => $subject,
			"statusFilter" => $status, "status" => $status,
			"limit" => 26, "offset" => ($page - 1) * 25,
		]);
		$entries = [];
		$hasNext = false;
		foreach($rows as $row) {
			if(count($entries) === 25) {
				$hasNext = true;
				break;
			}
			$entry = [];
			foreach(["id", "createdAt", "scheduledToSendAt", "sentAt", "title",
				"toEmail", "subject", "status"] as $key) {
				$entry[$key] = $row->getString($key) ?? "—";
			}
			$entries []= $entry;
		}
		return new EmailLog($entries, $page, $hasNext);
	}

	/** @param array<string, string> $kvp */
	public function schedule(
		User|AnonUser $user,
		ApplicationDeployment $deployment,
		string $toAddress,
		string $templateName,
		array $kvp = [],
		string $fromAddress = self::DEFAULT_EMAIL_FROM_ADDRESS,
		string $fromName = self::DEFAULT_EMAIL_FROM_NAME,
		?DateTimeInterface $when = null,
	):string {
		$emailId = $this->queue($user, $deployment, $toAddress, $templateName,
			$kvp, $fromAddress, $fromName, $when);
		$this->sendScheduled();
		return $emailId;
	}

	/** @param array<string, string> $kvp */
	private function queue(
		User|AnonUser $user,
		ApplicationDeployment $deployment,
		string $toAddress,
		string $templateName,
		array $kvp = [],
		string $fromAddress = self::DEFAULT_EMAIL_FROM_ADDRESS,
		string $fromName = self::DEFAULT_EMAIL_FROM_NAME,
		?DateTimeInterface $when = null,
	):string {
		$toAddress = (string)new EmailAddress($toAddress);
		$fromAddress = (string)new EmailAddress($fromAddress);
		$rendered = $this->templates?->render($deployment, $templateName, $kvp)
			?? (new EmailTemplate())->render($templateName, $kvp);
		$when ??= new DateTime();

		$emailId = new Ulid();
		$this->database->insert("schedule", [
			"id" => $emailId,
			"deploymentId" => $deployment->id,
			"scheduledToSendAt" => $when,
			"subject" => $rendered["subject"],
			"toEmail" => $toAddress,
			"senderName" => $deployment->getSenderName($fromName),
			"senderAddress" => $fromAddress,
			"textContent" => $rendered["text"],
			"htmlContent" => $rendered["html"],
		]);
		$this->audit->recordChange(
			Action::EMAIL_SCHEDULED,
			before: null,
			after: (object)[
				"emailId" => (string)$emailId,
				"deploymentId" => $deployment->id,
				"template" => $templateName,
				"recipient" => $toAddress,
			],
			actor: $user,
		);

		return $emailId;
	}

	public function queueLoginInstructions(User $actor, User $recipient):string {
		$deployment = $recipient->deployment;
		$url = (string)$deployment->getClientReturnUri();

		return $this->queue($actor, $deployment, $recipient->email, "loginInstructions.html", [
			"siteName" => $deployment->application->name,
			"applicationName" => $deployment->application->name,
			"email" => $recipient->email,
			"loginUrl" => $url,
			"authenticationInstructions" => $recipient->hash === null
				? "Choose the email security code option. We will send a code to your inbox."
				: "Use the password arranged with your administrator, or choose an email security code.",
		] + (new EmailBranding())->placeholders($deployment),
			$deployment->getSenderAddress() ?? self::DEFAULT_EMAIL_FROM_ADDRESS,
			$deployment->application->name);
	}

	public function scheduleAuthCode(
		User|AnonUser $user,
		ApplicationDeployment $deployment,
		string $email,
		string $siteName,
		string $code,
		string $fromEmail,
	):string {
		return $this->schedule(
			$user,
			$deployment,
			$email,
			"securityCode",
			[
				"code" => $code,
				"siteName" => $siteName,
			] + (new EmailBranding())->placeholders($deployment),
			$fromEmail,
			$siteName,
		);
	}

	/** @return array<string> */
	public function sendScheduled():array {
		$sentEmailIdList = [];

		foreach($this->database->fetchAll("getScheduled") as $row) {
			$emailSettings = $this->defaultEmailSettings;

			if($emailSettingsObj = json_decode($row->getString("emailSettings"), true)) {
				$emailSettings = new EmailSettings(
					$emailSettingsObj["host"] ?? $emailSettings->host,
					$emailSettingsObj["port"] ?? $emailSettings->port,
					$emailSettingsObj["username"] ?? $emailSettings->username,
					$emailSettingsObj["password"] ?? $emailSettings->password,
					$emailSettingsObj["replyTo"] ?? $emailSettings->replyTo,
				);
			}

			try {
				$sentMessageId = $this->send(
					$row->getString("senderName"),
					$row->getString("senderAddress"),
					$row->getString("toEmail"),
					$row->getString("subject"),
					$row->getString("textContent"),
					$row->getString("htmlContent"),
					$emailSettings,
					$row->getString("clientHost") ?? "",
					$row->getString("emailReplyTo"),
				);
			}
			catch(TransportExceptionInterface $exception) {
				$this->database->update("markAsFailed", ["id" => $row->getString("id")]);
				throw $exception;
			}

			if($sentMessageId === null) {
				$this->database->update("markAsFailed", ["id" => $row->getString("id")]);
				continue;
			}

			$this->database->update("markAsSent", [
				"id" => $row->getString("id"),
				"sentMessageId" => $sentMessageId,
			]);

			array_push($sentEmailIdList, $row->getString("id"));
		}

		return $sentEmailIdList;
	}

	/** Uses stateless utility/framework APIs.
	* @SuppressWarnings("PHPMD.StaticAccess")
	*/
	public function send(
		string $fromName,
		string $fromAddress,
		string $toAddress,
		string $subject,
		string $markdown,
		string $html,
		EmailSettings $emailSettings,
		string $clientUrl = "",
		?string $replyTo = null,
	):?string {
		$transport = Transport::fromDsn(implode("", [
			"smtp://",
			rawurlencode($emailSettings->username ?? ""),
			":",
			rawurlencode($emailSettings->password ?? ""),
			"@",
			$emailSettings->host,
			":",
			$emailSettings->port,
		]));

		$mailer = $this->mailer ?? new Mailer($transport);
		$email = new Email();
		$email->addFrom(new Address((string)new EmailAddress($fromAddress), $fromName));
		$email->addTo(new Address((string)new EmailAddress($toAddress)));
		if($replyTo = $emailSettings->getReplyTo($clientUrl, $replyTo)) {
			$email->replyTo(new Address((string)new EmailAddress($replyTo)));
		}
		$email->subject($subject);
		$email->text($markdown);
		$email->html($html);

		$ulid = new Ulid("EMAIL");
		$headers = $email->getHeaders();
		$headers->addIdHeader("Message-ID", "$ulid@authwave.com");
		$authwaveVersion = shell_exec("git rev-parse HEAD") ?? "unknown";
		$authwaveVersion = trim($authwaveVersion);
		$headers->addHeader("X-AUTHWAVE-VERSION", $authwaveVersion);

		try {
			$mailer->send($email);
		}
		catch(TransportExceptionInterface $exception) {
			if(!$this->ignoreTransportErrors) {
				throw $exception;
			}

			return null;
		}

		return $ulid;
	}
}
