<?php
namespace Authwave\Email;

class ConfirmationCode {
	const NUMBER_COUNT = 8;

	private string $code;

	public function __construct(string $code = null) {
		if(is_null($code)) {
			$code = $this->generateCode();
		}

		$this->code = $code;
	}

	public function getFormatted():string {
		$codeParts = str_split($this->code, 4);
		return implode("-", $codeParts);
	}

	private function generateCode():string {
		$code = "";

		for($i = 0; $i < self::NUMBER_COUNT; $i++) {
			$code .= rand(0, 9);
		}

		return $code;
	}

	public function __toString():string {
		return $this->code;
	}
}