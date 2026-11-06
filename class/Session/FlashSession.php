<?php
namespace Authwave\Session;

use GT\Session\SessionStoreInterface;

readonly class FlashSession {
	const SESSION_KEY = "flash";

	public function __construct(
		private SessionStoreInterface $session
	) {}

	public function setFlash(string $message, string $category = "main"):void {
		$this->session->set($category, $message);
	}

	public function getFlash(string $category = "main"):?string {
		$currentFlash = $this->session->getString($category);
		$this->session->remove($category);
		return $currentFlash;
	}
}
