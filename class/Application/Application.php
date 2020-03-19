<?php
namespace Authwave\Application;

class Application {
	private int $id;
	private string $displayName;

	public function __construct(
		int $id,
		string $displayName
	) {
		$this->id = $id;
		$this->displayName = $displayName;
	}
}