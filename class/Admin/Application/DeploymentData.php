<?php
namespace Authwave\Admin\Application;

/** Normalised values from an already validated deployment form. */
readonly class DeploymentData {
	public string $name;
	public string $host;
	public string $clientUrl;
	public string $clientLoginPath;

	public function __construct(string $name, string $host, string $clientUrl, string $path) {
		$this->name = trim($name);
		$this->host = strtolower(trim($host));
		$this->clientLoginPath = trim($path);

		$parts = parse_url(trim($clientUrl));
		$this->clientUrl = strtolower($parts["scheme"]) . "://" . strtolower($parts["host"])
			. (isset($parts["port"]) ? ":" . $parts["port"] : "");
	}
}
