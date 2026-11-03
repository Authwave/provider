<?php

namespace Authwave\Test\Integration\Behat;

use Authwave\Test\Behat\DatabaseState;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
require_once dirname(__DIR__, 2) . "/behat/database/DatabaseState.php";

class DatabaseStateTest extends TestCase {
	private string $directory;

	protected function setUp():void {
		$this->directory = sys_get_temp_dir() . "/authwave-state-" . bin2hex(random_bytes(8));
		mkdir($this->directory);
		mkdir("$this->directory/schema");
		mkdir("$this->directory/new_admin");
		mkdir("$this->directory/broken");
		file_put_contents("$this->directory/schema/001.sql", "create table account (name text);");
		file_put_contents("$this->directory/new_admin/001.sql", "insert into account values ('New admin');");
		file_put_contents("$this->directory/broken/001.sql", "invalid sql;");
	}

	protected function tearDown():void {
		foreach(glob("$this->directory/*/*") as $file) unlink($file);
		foreach(glob("$this->directory/*") as $file) {
			is_dir($file) ? rmdir($file) : unlink($file);
		}
		rmdir($this->directory);
	}

	public function testRestore_restoresExactSnapshotAndDoesNotReplaceItOnAnotherContext():void {
		$path = "$this->directory/test.sqlite";
		$original = new PDO("sqlite:$path");
		$original->exec("create table untouched (value text)");
		$original->exec("insert into untouched values ('original data')");
		unset($original);
		$hash = hash_file("sha256", $path);
		$sut = new DatabaseState($path, $this->directory);
		$sut->apply("new_admin");
		$connection = new PDO("sqlite:$path");
		self::assertSame("New admin", $connection->query("select name from account")->fetchColumn());
		unset($connection);
		$anotherContext = new DatabaseState($path, $this->directory);
		$anotherContext->restore();
		self::assertSame($hash, hash_file("sha256", $path));
		$sut->apply("new_admin");
		$sut->restore();
		self::assertSame($hash, hash_file("sha256", $path));
	}

	public function testApply_failedMigrationsRestoreOriginalDatabase():void {
		$path = "$this->directory/test.sqlite";
		$sut = new DatabaseState($path, $this->directory);
		$hash = hash_file("sha256", $path);
		try {
			$sut->apply("broken");
			self::fail("Expected a migration failure.");
		}
		catch(\PDOException) {
			self::assertSame($hash, hash_file("sha256", $path));
		}
	}

	public function testApply_missingStateCannotLoadAnArbitraryPath():void {
		$sut = new DatabaseState("$this->directory/test.sqlite", $this->directory);
		$this->expectException(InvalidArgumentException::class);
		$sut->apply("../schema");
	}

	public function testApply_emptyStateCannotSilentlyPass():void {
		mkdir("$this->directory/empty");
		$sut = new DatabaseState("$this->directory/test.sqlite", $this->directory);
		$this->expectException(RuntimeException::class);
		$sut->apply("empty");
	}
}
