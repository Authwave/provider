<?php
namespace Authwave\Admin\Application;

use InvalidArgumentException;

readonly class DeploymentData {
	public string $name;
	public string $host;
	public string $clientUrl;
	public string $clientLoginPath;

	public function __construct(?string $name, ?string $host, ?string $clientUrl, ?string $path) {
		$this->name = $this->requiredText($name, "Deployment name", 32);
		$this->host = $this->normaliseHost($host);
		$this->clientUrl = $this->normaliseClientUrl($clientUrl);
		$this->clientLoginPath = $this->validateLoginPath($path);
	}

	private function normaliseHost(?string $host):string {
		$host = strtolower($this->requiredText($host, "Host", 256));
		$parts = parse_url("https://" . $host);

		if(!$parts || isset($parts["user"]) || isset($parts["pass"])
		|| isset($parts["path"]) || isset($parts["query"]) || isset($parts["fragment"])
		|| !isset($parts["host"]) || !$this->isValidHost($parts["host"])) {
			throw new InvalidArgumentException("Host must be a hostname with an optional port.");
		}

		return $host;
	}

	private function normaliseClientUrl(?string $clientUrl):string {
		$url = $this->requiredText($clientUrl, "Client URL", 256);
		$parts = parse_url($url);

		if(!$parts || !in_array(strtolower($parts["scheme"] ?? ""), ["http", "https"], true)
		|| !isset($parts["host"]) || !$this->isValidHost($parts["host"])
		|| isset($parts["user"]) || isset($parts["pass"]) || isset($parts["query"])
		|| isset($parts["fragment"]) || !in_array($parts["path"] ?? "", ["", "/"], true)) {
			throw new InvalidArgumentException("Client URL must be an HTTP or HTTPS origin.");
		}

		return strtolower($parts["scheme"]) . "://" . strtolower($parts["host"])
			. (isset($parts["port"]) ? ":" . $parts["port"] : "");
	}

	private function validateLoginPath(?string $path):string {
		$path = $this->requiredText($path, "Client login path", 256);
		$decoded = rawurldecode($path);

		if(!str_starts_with($path, "/") || str_starts_with($decoded, "//")
		|| preg_match('/[\\\\\s?#\x00-\x1f\x7f]/u', $decoded)
		|| preg_match("/%(?![a-f0-9]{2})/i", $path)) {
			throw new InvalidArgumentException("Client login path must be a root-relative path.");
		}

		return $path;
	}

	private function requiredText(?string $value, string $label, int $maximum):string {
		$value = trim($value ?? "");

		if($value === "" || !mb_check_encoding($value) || mb_strlen($value) > $maximum
		|| preg_match('/\p{C}/u', $value)) {
			throw new InvalidArgumentException(
				"$label is required and must be at most $maximum characters.");
		}

		return $value;
	}

	private function isValidHost(string $host):bool {
		return filter_var(trim($host, "[]"), FILTER_VALIDATE_IP) !== false
			|| filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
	}
}
