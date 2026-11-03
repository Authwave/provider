<?php
namespace Authwave\UI;

readonly class DeletionPrompt {
	public string $message;
	public string $confirmationUrl;

	public function __construct(
		public string $identifier,
		public string $name,
		public string $returnUrl,
	) {
		$this->message = "Confirm deleting " . $name;
		$this->confirmationUrl = $returnUrl . "?confirm-delete=" . rawurlencode($identifier)
			. "#delete-confirmation";
	}

	public function isConfirmed(mixed $value):bool {
		return is_string($value) && $value === $this->identifier;
	}
}
