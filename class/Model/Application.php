<?php
namespace Authwave\Model;

readonly class Application {
	/** @param ApplicationTheme[] $themes
	* @SuppressWarnings("PHPMD.ShortVariable")
	*/
	public function __construct(
		public string $id,
		public string $name,
		public ?string $emailSendFrom,
		public ?EmailSettings $emailSettings = null,
		public array $themes = [],
		public ?string $productionApplicationDeploymentId = null,
	) {}
	/** @return ApplicationTheme[] */
	public function getThemes():array {
		// Old serialised sessions may lack this field entirely.
		return get_object_vars($this)["themes"] ?? [];
	}
}
