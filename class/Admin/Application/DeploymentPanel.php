<?php
namespace Authwave\Admin\Application;

readonly class DeploymentPanel {
	public string $identifier;
	public string $name;
	public string $host;
	public string $clientUrl;
	public string $clientLoginPath;
	public string $apiKey;
	public string $summary;
	public string $submitLabel;
	public string $panelId;
	public bool $isNew;

	public function __construct(?ManagedDeployment $deployment, public bool $isOpen) {
		$this->identifier = $deployment->identifier ?? "";
		$this->name = $deployment->name ?? "";
		$this->host = $deployment->host ?? "";
		$this->clientUrl = $deployment->clientUrl ?? "";
		$this->clientLoginPath = $deployment->clientLoginPath ?? "/";
		$this->apiKey = $deployment->apiKey ?? "";
		$this->isNew = $deployment === null;
		$this->summary = $deployment->name ?? "Create a deployment";
		$this->submitLabel = $deployment ? "Save deployment" : "Create deployment";
		$this->panelId = "deployment-" . ($deployment->identifier ?? "new");
	}
}
