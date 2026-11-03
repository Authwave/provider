<?php
namespace Authwave\Admin\Application;

readonly class ApplicationSettings {
	public string $url;
	public function __construct(
		public string $identifier,
		public string $name,
		public ?string $productionDeploymentId,
	) {
		$this->url = "/admin/applications/" . rawurlencode($identifier) . "/";
	}
}
