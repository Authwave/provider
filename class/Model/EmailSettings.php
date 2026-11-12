<?php
namespace Authwave\Model;

use GT\Http\Uri;

readonly class EmailSettings {
	public function __construct(
		public ?string $host,
		public ?int $port,
		public ?string $username,
		public ?string $password,
		public ?string $replyTo = null,
	) {}

	public function getReplyTo(string $providerHost):?string {
		$replyTo = trim($this->replyTo ?? "");
		if($replyTo !== "") {
			return $replyTo;
		}
		if($providerHost === "") {
			return null;
		}

		$host = strtolower(rtrim((new Uri("https://$providerHost"))->getHost(), "."));
		if(!str_contains($host, ".") || filter_var(trim($host, "[]"), FILTER_VALIDATE_IP)) {
			return null;
		}

		$labels = explode(".", $host);
		if(count($labels) > 2) {
			array_shift($labels);
		}

		return "support@" . implode(".", $labels);
	}
}
