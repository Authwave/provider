<?php
namespace Authwave\UI;

readonly class DeletionPrompt {
	public string $message;
	public string $confirmationUrl;

	public function __construct(
		public string $id,
		public string $name,
		public string $returnUrl,
	) {
		$this->message = "Confirm deleting " . $name;
		$this->confirmationUrl = $returnUrl . "?confirm-delete=" . rawurlencode($id)
			. "#delete-confirmation";
	}

	public function isConfirmed(mixed $value):bool {
		return is_string($value) && $value === $this->id;
	}
}
