<?php
namespace Authwave\Admin\Application;

use GT\Input\Input;

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

	public function __construct(
		?ManagedDeployment $deployment,
		public bool $isOpen = false,
		?Input $input = null,
		public string $error = "",
	) {
		$this->identifier = $deployment->identifier ?? "";
		$this->name = $this->field($input, "deploymentName", $deployment->name ?? "");
		$this->host = $this->field($input, "host", $deployment->host ?? "");
		$this->clientUrl = $this->field($input, "clientUrl", $deployment->clientUrl ?? "");
		$this->clientLoginPath = $this->field($input, "clientLoginPath",
			$deployment->clientLoginPath ?? "/");
		$this->apiKey = $deployment->apiKey ?? "";
		$this->isNew = $deployment === null;
		$this->summary = $deployment->name ?? "Create a deployment";
		$this->submitLabel = $deployment ? "Save deployment" : "Create deployment";
		$this->panelId = "deployment-" . ($deployment->identifier ?? "new");
	}

	private function field(?Input $input, string $name, string $fallback):string {
		if($input === null) {
			return $fallback;
		}

		$value = $input->get($name, Input::DATA_BODY);
		return is_string($value) ? $value : "";
	}
}
