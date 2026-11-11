<?php
namespace Authwave\Security;

use GT\Http\ResponseStatusException\ResponseStatusException;

class AdminAccessDenied extends ResponseStatusException {
	public function getHttpCode():int {
		return 403;
	}
}
