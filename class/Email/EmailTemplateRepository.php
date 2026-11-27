<?php
namespace Authwave\Email;

use Authwave\Model\{ApplicationDeployment, ApplicationRepository, User};
use Authwave\Security\{Audit, Action};
use GT\Database\Database;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

readonly class EmailTemplateRepository {
	public const OPTIONS = [
		"securityCode" => "Security code",
		"loginInstructions" => "Login instructions",
		"passwordChanged" => "Password changed",
	];

	public function __construct(private Database $database,
		private ApplicationRepository $applications, private Audit $audit) {}

	/** @return array{subject: string, html: string, custom: bool} */
	public function get(User $actor, string $applicationId, string $name,
		string $deploymentId = ""):array {
		$this->scope($actor, $applicationId, $deploymentId);
		$name = $this->name($name);
		$row = $this->database->queryCollection("email_template")->fetch("get",
			$this->key($applicationId, $deploymentId, $name));
		if($row !== null) {
			return ["subject" => $row->getString("subject"), "html" => $row->getString("htmlContent"),
				"custom" => true];
		}
		return $this->inherited($applicationId, $deploymentId, $name) + ["custom" => false];
	}

	public function save(User $actor, string $applicationId, string $name,
		string $deploymentId, string $subject, string $html):void {
		$this->scope($actor, $applicationId, $deploymentId);
		$name = $this->name($name);
		$rendered = $this->validate($name, $subject, $html);
		$key = $this->key($applicationId, $deploymentId, $name);
		$this->transaction(function()use(
			$actor, $applicationId, $deploymentId, $name, $key, $subject, $rendered,
		):void {
			$queries = $this->database->queryCollection("email_template");
			$exists = $queries->fetch("get", $key) !== null;
			$fields = $key + ["subject" => trim($subject), "htmlContent" => $rendered["html"]];
			$inherited = $this->inherited($applicationId, $deploymentId, $name);
			$defaultHtml = (new EmailTemplate())->renderHtml($inherited["html"],
				subject: $inherited["subject"])["html"];
			$usingDefault = trim($subject) === $inherited["subject"]
				&& trim($rendered["html"]) === trim(str_replace(["\r\n", "\r"], "\n", $defaultHtml));
			if($usingDefault) {
				$queries->delete("delete", $key);
			}
			elseif($exists) {
				$queries->update("update", $fields);
			}
			else {
				$queries->insert("create", $fields + ["deploymentId" => $deploymentId ?: null]);
			}
			$this->audit->create(Action::EMAIL_TEMPLATE_UPDATED, [
				"applicationId" => $applicationId, "deploymentId" => $deploymentId ?: null,
				"template" => $name, "customTemplate" => !$usingDefault,
			], $actor);
		});
	}

	public function reset(User $actor, string $applicationId, string $name,
		string $deploymentId):void {
		$this->scope($actor, $applicationId, $deploymentId);
		$this->transaction(function()use($actor, $applicationId, $name, $deploymentId):void {
			$this->database->queryCollection("email_template")->delete("delete",
				$this->key($applicationId, $deploymentId, $this->name($name)));
			$this->audit->create(Action::EMAIL_TEMPLATE_RESET, [
				"applicationId" => $applicationId, "deploymentId" => $deploymentId ?: null,
				"template" => $name,
			], $actor);
		});
	}

	/** @return array{subject: string, text: string, html: string} */
	public function preview(User $actor, string $applicationId, string $name,
		string $deploymentId, string $subject, string $html):array {
		$deployment = $this->scope($actor, $applicationId, $deploymentId);
		$this->validate($this->name($name), $subject, $html);
		$values = (new EmailBranding())->placeholders($deployment) + [
			"siteName" => $deployment->application->name,
			"applicationName" => $deployment->application->name,
			"code" => "123456", "email" => "person@example.test",
			"loginUrl" => (string)$deployment->getClientReturnUri(),
			"authenticationInstructions" => "Choose the email security code option to sign in.",
		];
		return (new EmailTemplate())->renderHtml($html, $values, $subject);
	}

	/** @param array<string, string> $values
	 * @return array{subject: string, text: string, html: string}
	 */
	public function render(ApplicationDeployment $deployment, string $name, array $values):array {
		$baseName = pathinfo($name, PATHINFO_FILENAME);
		if(!isset(self::OPTIONS[$baseName])) {
			return (new EmailTemplate())->render($name, $values);
		}
		$row = $this->database->queryCollection("email_template")->fetch("effective", [
			"applicationId" => $deployment->applicationId, "deploymentId" => $deployment->id,
			"name" => $baseName,
		]);
		return $row === null ? (new EmailTemplate())->render($name, $values)
			: (new EmailTemplate())->renderHtml($row->getString("htmlContent"), $values,
				$row->getString("subject"));
	}

	/** @return array{subject: string, html: string} */
	private function inherited(string $applicationId, string $deploymentId, string $name):array {
		if($deploymentId !== "") {
			$row = $this->database->queryCollection("email_template")->fetch("get",
				$this->key($applicationId, "", $name));
			if($row !== null) {
				return ["subject" => $row->getString("subject"), "html" => $row->getString("htmlContent")];
			}
		}
		$default = (new EmailTemplate())->render($name);
		return ["subject" => $default["subject"], "html" => $default["html"]];
	}

	private function scope(User $actor, string $applicationId,
		string $deploymentId):ApplicationDeployment {
		$application = $this->applications->get($actor, $applicationId);
		$deployments = $this->applications->deployments($actor, $applicationId);
		foreach($deployments as $deployment) {
			if($deployment->id === $deploymentId) {
				return $deployment;
			}
		}
		if($deploymentId !== "") {
			throw new InvalidArgumentException("Choose a deployment assigned to this application.");
		}
		return $deployments[0] ?? new ApplicationDeployment("preview", $application,
			"Preview", "", "https://example.test", "/login/", "login.example.test");
	}

	private function name(string $name):string {
		if(!isset(self::OPTIONS[$name])) {
			throw new InvalidArgumentException("Choose a valid email template.");
		}
		return $name;
	}

	/** @return array<string, string> */
	private function key(string $applicationId, string $deploymentId, string $name):array {
		return ["applicationId" => $applicationId, "scopeId" => $deploymentId ?: $applicationId,
			"name" => $name];
	}

	/** @return array{subject: string, text: string, html: string} */
	private function validate(string $name, string $subject, string $html):array {
		if(trim($subject) === "" || strlen($subject) > 256
		|| preg_match('/\A[^\p{C}]+\z/u', $subject) !== 1) {
			throw new InvalidArgumentException(
				"Enter a subject of up to 256 characters without control characters.");
		}
		if(trim($html) === "" || strlen($html) > 1048576) {
			throw new InvalidArgumentException("Enter an HTML template of up to 1 MB.");
		}
		$html = str_replace(["\r\n", "\r"], "\n", $html);
		try {
			$rendered = (new EmailTemplate())->renderHtml($html, subject: trim($subject));
		}
		catch(UnexpectedValueException $error) {
			throw new InvalidArgumentException($error->getMessage(), previous: $error);
		}
		$required = match($name) {
			"securityCode" => ["code"],
			"loginInstructions" => ["loginUrl", "authenticationInstructions"],
			default => [],
		};
		$body = preg_replace('/<head\b[^>]*>.*?<\/head>|<title\b[^>]*>.*?<\/title>/is',
			"", $rendered["html"]);
		foreach($required as $placeholder) {
			if(!str_contains($body, "{{" . $placeholder . "}}")) {
				throw new InvalidArgumentException(
					"Keep the {{" . $placeholder . "}} placeholder in the HTML template.");
			}
		}
		return $rendered;
	}
	private function transaction(callable $operation):void {
		$connection = $this->database->getDriver()->getConnection();
		$connection->beginTransaction();
		try {
			$operation();
			$connection->commit();
		}
		catch(Throwable $error) {
			if($connection->inTransaction()) {
				$connection->rollBack();
			}
			throw $error;
		}
	}

}
