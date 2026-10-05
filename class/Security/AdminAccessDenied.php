<?php
namespace Authwave\Security;

use Gt\Http\ResponseStatusException\ResponseStatusException;

class AdminAccessDenied extends ResponseStatusException {
	public function getHttpCode():int {
		return 403;
	}
}
