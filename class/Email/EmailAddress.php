<?php
namespace Authwave\Email;

use Stringable;

class EmailAddress implements Stringable {
	public function __construct(private readonly string $address) {}

	public function __toString():string {
		return self::normalize($this->address);
	}

	public static function normalize(string $address):string {
		$address = trim($address);
		$separator = strrpos($address, "@");
		if($separator === false) {
			return $address;
		}

		$local = substr($address, 0, $separator);
		return trim($local, ".") . substr($address, $separator);
	}
}
