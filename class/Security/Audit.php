<?php
namespace Authwave\Security;

use Authwave\User\User;
use Gt\Database\Query\QueryCollection;
use Gt\Ulid\Ulid;

class Audit {
	public function __construct(
		private readonly QueryCollection $db,
	) {}

	/** @param null|array<string, string> $detail */
	public function create(
		Action $action,
		?array $detail,
		null|User|AnonUser $user = null,
	):void {
		$this->db->insert("create", [
			"id" => new Ulid("AUDIT"),
			"action" => $action->name,
			"detail" => is_null($detail)
				? null
				: json_encode(
					$detail,
					JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT,
				),
			"userId" => $user instanceof User ? $user->id : null,
			"anonUserId" => $user instanceof AnonUser ? $user->id : null,
		]);
	}
}
