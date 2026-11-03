<?php
namespace Authwave\Admin\Application;

use InvalidArgumentException;

readonly class ApplicationName {
	public string $value;

	public function __construct(mixed $value) {
		if($value !== null && !is_string($value)) {
			throw new InvalidArgumentException("Enter a single application name.");
		}

		$value = trim($value ?? "");

		if($value === "") {
			throw new InvalidArgumentException("Enter an application name.");
		}

		if(!mb_check_encoding($value, "UTF-8") || preg_match('/\p{C}/u', $value)) {
			throw new InvalidArgumentException("Application names cannot contain control characters.");
		}

		if(mb_strlen($value) > 128) {
			throw new InvalidArgumentException("Application names must be 128 characters or fewer.");
		}

		$this->value = $value;
	}
}
