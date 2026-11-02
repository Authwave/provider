<?php
namespace Authwave\Model;

class Application {
	/** @param ApplicationTheme[] $themes
	* @SuppressWarnings("PHPMD.ShortVariable")
	*/
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $emailSendFrom,
		public readonly ?EmailSettings $emailSettings = null,
		public readonly array $themes = [],
	) {}
	/** @return ApplicationTheme[] */
	public function getThemes():array {
		// Old serialised sessions may lack this field entirely.
		return get_object_vars($this)["themes"] ?? [];
	}
}
