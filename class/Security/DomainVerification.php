<?php
namespace Authwave\Security;

use Closure;
use ErrorException;

readonly class DomainVerification {
	const DEFAULT_CONFIRMATION = "dev-2611";

	/** @var Closure(string, int):(list<array<string, mixed>>|false) */
	private Closure $lookup;

	/** @param null|Closure(string, int):(list<array<string, mixed>>|false) $lookup */
	public function __construct(
		private string $confirmation = self::DEFAULT_CONFIRMATION,
		?Closure $lookup = null,
	) {
		$this->lookup = $lookup ?? dns_get_record(...);
	}

	public function verify(string $host):bool {
		if($this->isLocal($host)) {
			return false;
		}

		$hostname = $this->hostname($host);

		if(str_ends_with($hostname, ".test")) {
			return true;
		}

		if($hostname === "" || $this->confirmation === "") {
			return false;
		}

		$nameservers = [];

		foreach($this->records($hostname, DNS_NS) as $record) {
			if(($record["type"] ?? "") === "NS" && is_string($record["target"] ?? null)) {
				$nameservers []= strtolower(rtrim($record["target"], "."));
			}
		}

		if(array_diff(["ns1.authwave.com", "ns2.authwave.com"], $nameservers)) {
			return false;
		}

		foreach($this->records("authwave-confirmation.$hostname", DNS_TXT) as $record) {
			if(($record["type"] ?? "") === "TXT" && ($record["txt"] ?? null) === $this->confirmation) {
				return true;
			}
		}

		return false;
	}

	public function hostname(string $host):string {
		return strtolower(rtrim(explode(":", $host, 2)[0], "."));
	}

	public function isLocal(string $host):bool {
		$hostname = $this->hostname($host);

		if($hostname === "localhost") {
			return true;
		}

		return str_starts_with($hostname, "127.")
			&& filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
	}

	/** @return list<array<string, mixed>> */
	private function records(string $hostname, int $type):array {
		set_error_handler(fn(int $severity, string $message):never
			=> throw new ErrorException($message, 0, $severity));

		try {
			return ($this->lookup)($hostname, $type) ?: [];
		}
		catch(ErrorException) {
			return [];
		}
		finally {
			restore_error_handler();
		}
	}
}
