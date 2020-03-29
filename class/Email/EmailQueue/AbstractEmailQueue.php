<?php
namespace Authwave\Email\EmailQueue;

use Authwave\User\User;
use Gt\Database\Query\QueryCollection;

abstract class AbstractEmailQueue {
	private QueryCollection $db;
	private User $user;

	public function __construct(QueryCollection $db) {
		$this->db = $db;
	}

	abstract protected function getSubject():string;
	abstract protected function getBodyText():string;
	abstract protected function getBodyHtml():string;

	public function setUser(User $user):void {
		$this->user = $user;
	}

	public function addToQueue():void {
		$this->db->insert(
			"addToSendQueue", [
			"userId" => $this->user->getId(),
			"toAddress" => $this->user->getEmail(),
			"fromAddress" => "", //TODO
			"subject" => $this->getSubject(),
			"bodyText" => $this->getBodyText(),
			"bodyHtml" => $this->getBodyHtml()
		]);
	}
}