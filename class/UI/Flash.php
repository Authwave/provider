<?php
namespace Authwave\UI;

use Gt\Session\SessionStore;

class Flash {
	const SESSION_NAMESPACE = "authwave.flash";
	const SESSION_KEY = "flashdata";

	const QUEUE_ERROR = "error";
	const QUEUE_WARNING = "warning";
	const QUEUE_SUCCESS = "success";

	private SessionStore $session;
	private array $flashQueue = [];

	public function __construct(SessionStore $session) {
		$this->session = $session;

		$this->flashQueue = $this->session->get(self::SESSION_KEY) ?? [];
	}

	public function __destruct() {
		$this->session->set(self::SESSION_KEY, $this->flashQueue);
	}

	public function error(string $message):void {
		$this->flashQueue(self::QUEUE_ERROR, $message);
	}

	public function warning(string $message):void {
		$this->flashQueue(self::QUEUE_WARNING, $message);
	}

	public function success(string $message):void {
		$this->flashQueue(self::QUEUE_SUCCESS, $message);
	}

	public function flashQueue(string $queue, string $message):void {
		if(!isset($this->flashQueue[$queue])) {
			$this->flashQueue[$queue] = [];
		}

		$this->flashQueue[$queue] []= $message;
	}

	public function getQueue():array {
		return $this->flashQueue;
	}

	public function clear():void {
		$this->flashQueue = [];
	}
}