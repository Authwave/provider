<?php
namespace Authwave\User;

use Gt\Database\Query\QueryCollection;
use Gt\Session\SessionStore;

class UserRepository {
	public const SESSION_KEY = "authwave.user";

	private QueryCollection $db;
	private SessionStore $session;

	public function __construct(
		QueryCollection $db,
		SessionStore $session
	) {
		$this->db = $db;
		$this->session = $session;
	}
}