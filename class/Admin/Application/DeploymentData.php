<?php
namespace Authwave\Admin\Application;

use InvalidArgumentException;

/** Normalised values from an already validated deployment form. */
readonly class DeploymentData {
	public string $title;
	public string $providerHost;
	public string $clientHost;
	public string $clientLoginPath;
	public ?string $emailPrefix;
	public ?string $emailReplyTo;

	public function __construct(string $title, string $providerHost,
		string $clientHost, string $clientLoginPath, string $emailPrefix = "security",
		string $emailReplyTo = "") {
		$this->emailPrefix = trim($emailPrefix) === "" ? null : trim($emailPrefix);
		$this->emailReplyTo = trim($emailReplyTo) === "" ? null : trim($emailReplyTo);
		if($this->emailPrefix !== null && (strlen($this->emailPrefix) > 64
			|| !preg_match('/\A[A-Za-z0-9]+(?:[._+\-][A-Za-z0-9]+)*\z/', $this->emailPrefix))) {
			throw new InvalidArgumentException(
				"Enter a valid sender prefix, up to 64 characters, without an @ sign.");
		}
		if($this->emailReplyTo !== null && (strlen($this->emailReplyTo) > 254
			|| !filter_var($this->emailReplyTo, FILTER_VALIDATE_EMAIL))) {
			throw new InvalidArgumentException("Enter a valid Reply-To email address.");
		}
		$this->title = trim($title);
		$this->providerHost = strtolower(trim($providerHost));
		$senderHost = explode(":", $this->providerHost, 2)[0];
		if($this->emailPrefix !== null && strlen("$this->emailPrefix@$senderHost") > 254) {
			throw new InvalidArgumentException("The sender email address must be at most 254 characters.");
		}
		$this->clientLoginPath = trim($clientLoginPath);

		$parts = parse_url(trim($clientHost));
		$this->clientHost = strtolower($parts["scheme"]) . "://" . strtolower($parts["host"])
			. (isset($parts["port"]) ? ":" . $parts["port"] : "");
	}
}
