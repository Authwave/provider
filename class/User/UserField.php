<?php
namespace Authwave\User;

use Authwave\Application\ApplicationField;

class UserField {
	private User $user;
	private ApplicationField $field;
	private int $id;
	private string $value;

	public function __construct(
		User $user,
		ApplicationField $field,
		int $userFieldId,
		string $value
	) {
		$this->user = $user;
		$this->field = $field;
		$this->id = $userFieldId;
		$this->value = $value;
	}
}