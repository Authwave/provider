<?php
namespace Authwave\Test\Behat;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** Snapshots and restores the disposable Behat database, never the developer database. */
class DatabaseState {
	private readonly string $snapshot;

	public function __construct(
		private readonly string $database,
		private readonly string $states,
	) {
		$this->snapshot = "$database.snapshot";
		if(!is_file($database)) {
			new PDO("sqlite:$database");
		}
		if(!is_file($this->snapshot) && !copy($database, $this->snapshot)) {
			throw new RuntimeException("Could not snapshot the Behat database.");
		}
	}

	public function apply(string $state):void {
		if(!preg_match('/^[a-z][a-z_]*$/', $state) || !is_dir("$this->states/$state")) {
			throw new InvalidArgumentException("Unknown Behat database state: $state");
		}
		$this->restore();
		try {
			$connection = new PDO("sqlite:$this->database");
			$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			foreach(["schema", $state] as $directory) {
				$files = glob("$this->states/$directory/*.sql");
				if(!$files) {
					throw new RuntimeException("No SQL migrations found for $directory.");
				}
				foreach($files as $file) {
					$connection->exec(file_get_contents($file));
				}
			}
		}
		catch(Throwable $error) {
			unset($connection);
			$this->restore();
			throw $error;
		}
	}

	public function restore():void {
		if(!copy($this->snapshot, $this->database)) {
			throw new RuntimeException("Could not restore the Behat database snapshot.");
		}
	}
}
