<?php
namespace Authwave\Admin\Application;

/** Normalised values from an already validated deployment form. */
readonly class DeploymentData {
	public string $title;
	public string $providerHost;
	public string $clientHost;
	public string $clientLoginPath;

	public function __construct(string $title, string $providerHost,
		string $clientHost, string $clientLoginPath) {
		$this->title = trim($title);
		$this->providerHost = strtolower(trim($providerHost));
		$this->clientLoginPath = trim($clientLoginPath);

		$parts = parse_url(trim($clientHost));
		$this->clientHost = strtolower($parts["scheme"]) . "://" . strtolower($parts["host"])
			. (isset($parts["port"]) ? ":" . $parts["port"] : "");
	}
}
