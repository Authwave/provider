<?php
namespace Authwave\DataTransfer;


class AdminRequestData extends RequestData {
	public function __construct() {
		$cipher = "";
		$iv = "";
		parent::__construct($cipher, $iv, "/");
	}
}