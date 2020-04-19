<?php
namespace Authwave\Application;

class ApplicationField {
	private Application $application;
	private int $id;
	private ?string $type;
	private string $name;
	private string $displayName;
	private ?string $hint;
	private ?string $help;
	private bool $required;
	private bool $showOnSignUp;

	public function __construct(
		Application $application,
		int $id,
		?string $type,
		string $name,
		string $displayName,
		?string $hint,
		?string $help,
		bool $required,
		bool $showOnSignUp
	) {
		$this->application = $application;
		$this->id = $id;
		$this->type = $type;
		$this->name = $name;
		$this->displayName = $displayName;
		$this->hint = $hint;
		$this->help = $help;
		$this->required = $required;
		$this->showOnSignUp = $showOnSignUp;
	}

	public function getName():string {
		return $this->name;
	}

	public function getDisplayName():string {
		return $this->displayName;
	}

	public function getHint():?string {
		return $this->hint;
	}

	public function isRequired():bool {
		return $this->required;
	}

	public function doesShowOnSignUp():bool {
		return $this->showOnSignUp;
	}
}