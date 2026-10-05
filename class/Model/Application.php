<?php
namespace Authwave\Model;

class Application {
	/** @param ApplicationTheme[] $themes */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $emailSendFrom,
		?EmailSettings $emailSettings = null,
		public readonly array $themes = [],
	) {}
}
