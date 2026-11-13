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

	public function getReplyTo(string $clientUrl, ?string $deploymentReplyTo = null):?string {
		$deploymentReplyTo = trim($deploymentReplyTo ?? "");
		if($deploymentReplyTo !== "") {
			return $deploymentReplyTo;
		}
		$replyTo = trim($this->replyTo ?? "");
		if($replyTo !== "") {
			return $replyTo;
		}
		if($clientUrl === "") {
			return null;
		}

		$host = strtolower(rtrim((new Uri(str_contains($clientUrl, "://") ? $clientUrl : "https://$clientUrl"))->getHost(), "."));
		if(!str_contains($host, ".") || filter_var(trim($host, "[]"), FILTER_VALIDATE_IP)) {
			return null;
		}

		return "support@" . $host;
	}
}
