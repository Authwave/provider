<?php
namespace Authwave\Test\Behat;

use PHPUnit\Framework\TestCase;

class ParallelRunnerTest extends TestCase {
	private string $directory;

	protected function setUp():void {
		$this->directory = sys_get_temp_dir() . "/authwave-parallel-test-" . bin2hex(random_bytes(6));
		mkdir($this->directory . "/test/behat", 0777, true);
		copy("test/behat/run-parallel.php", $this->directory . "/test/behat/run-parallel.php");
		copy("test/behat/WorkerDisplay.php", $this->directory . "/test/behat/WorkerDisplay.php");
		symlink(realpath("vendor"), $this->directory . "/vendor");
		foreach(["one", "two", "three"] as $feature) {
			file_put_contents($this->directory . "/test/behat/$feature.feature", "Feature: $feature\n  Scenario: Run $feature\n    Given a fixture\n");
		}
		file_put_contents($this->directory . "/test/behat/run-behat", <<<'RUNNER'
#!/usr/bin/env php
<?php
$arguments = array_values(array_filter(array_slice($argv, 1), fn($argument) => !in_array($argument, ["--colors", "--no-colors"], true)));
if(getenv("BEHAT_PROGRESS_FILE")) {
file_put_contents(getenv("BEHAT_PROGRESS_FILE"), json_encode(["type" => "scenario", "feature" => __FILE__, "scenario" => 1, "scenarios" => 3, "title" => "A running scenario"]) . "\n");
file_put_contents(getenv("BEHAT_PROGRESS_FILE"), json_encode(["type" => "step", "time" => microtime(true), "title" => "Then progress is visible"]) . "\n", FILE_APPEND);
}
file_put_contents(__DIR__ . "/calls", json_encode($arguments) . "\n", FILE_APPEND | LOCK_EX);
if(getenv("TEST_PARALLEL_BARRIER") === "1") {
	$deadline = microtime(true) + 5;
	while(count(file(__DIR__ . "/calls")) < 2) {
		if(microtime(true) > $deadline) { exit(3); }
		usleep(10000);
	}
}
if(getenv("BEHAT_PROGRESS_FILE")) {
	foreach($arguments as $argument) {
		if(str_ends_with($argument, ".feature")) {
			file_put_contents(getenv("BEHAT_PROGRESS_FILE"), json_encode(["type" => "scenario-finished"]) . "\n", FILE_APPEND);
		}
	}
}
$colour = in_array("--colors", $argv, true);
echo ($colour ? "\033[32m" : "") . "BUFFERED TRANSCRIPT" . ($colour ? "\033[0m" : "") . "\n";
exit((int)getenv("TEST_RUNNER_EXIT"));
RUNNER);
		chmod($this->directory . "/test/behat/run-behat", 0755);
	}

	protected function tearDown():void {
		foreach(glob($this->directory . "/test/behat/*") as $file) {
			unlink($file);
		}
		rmdir($this->directory . "/test/behat");
		rmdir($this->directory . "/test");
		unlink($this->directory . "/vendor");
		rmdir($this->directory);
	}

	private function runRunner(array $arguments = [], array $environment = []):array {
		$environment = array_merge(getenv(), ["BEHAT_WORKERS" => "2", "BEHAT_INTERACTIVE" => "0", "BEHAT_PROGRESS_MODE" => "multi-line",
			"BEHAT_CHROME_API_URL" => "", "BEHAT_APP_PORT" => "", "BEHAT_CHROME_PORT" => "", "BEHAT_APP_COMMAND" => "",
			"TEST_PARALLEL_BARRIER" => "", "TEST_RUNNER_EXIT" => "0"], $environment);
		$process = proc_open([PHP_BINARY, $this->directory . "/test/behat/run-parallel.php", ...$arguments],
			[0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["redirect", 1]], $pipes, $this->directory, $environment);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		return [proc_close($process), $output];
	}

	public function testFullRun_partitionsFeaturesAcrossConcurrentWorkers():void {
		[$status, $output] = $this->runRunner(environment: ["TEST_PARALLEL_BARRIER" => "1"]);
		self::assertSame(0, $status, $output);
		$calls = array_map(fn(string $line):array => json_decode($line, true), file($this->directory . "/test/behat/calls"));
		self::assertCount(2, $calls);
		self::assertStringContainsString("F1/2 S1/3 - A running scenario", $output);
		self::assertStringContainsString("100% - Then progress is visible", $output);
		self::assertLessThan(strpos($output, "BUFFERED TRANSCRIPT"), strpos($output, "[passed]", strpos($output, "[passed]") + 1));
		$features = array_merge(...$calls);
		sort($features);
		self::assertSame(glob($this->directory . "/test/behat/*.feature"), $features);
	}

	public function testMissingComposerSettings_defaultsToOneUpdatingLine():void {
		[$status, $output] = $this->runRunner(environment: ["BEHAT_PROGRESS_MODE" => false]);
		self::assertSame(0, $status);
		self::assertStringContainsString("\r100% (2 workers) - Then progress is visible", $output);
		self::assertStringNotContainsString("Behat worker 1/2:", $output);
	}

	public function testSingleLineMode_keepsTranscriptsAfterTheUpdatingProgressLine():void {
		[$status, $output] = $this->runRunner(environment: ["BEHAT_PROGRESS_MODE" => "single-line"]);
		self::assertSame(0, $status);
		self::assertStringContainsString("\r100% (2 workers) - Then progress is visible", $output);
		self::assertStringNotContainsString("Behat worker 1/2:", $output);
		self::assertStringContainsString("\n\n--- Behat worker 1/2 (passed) ---", $output);
	}

	public function testColour_isPreservedInBufferedTranscripts():void {
		[$status, $output] = $this->runRunner(environment: ["FORCE_COLOR" => "1", "NO_COLOR" => false]);
		self::assertSame(0, $status);
		self::assertStringContainsString("\033[32mBUFFERED TRANSCRIPT\033[0m", $output);
	}

	public function testFailedWorker_failsTheRun():void {
		[$status] = $this->runRunner(environment: ["TEST_RUNNER_EXIT" => "1"]);
		self::assertSame(1, $status);
	}

	public function testScenarioSelection_forwardsArgumentsToOneRunner():void {
		$arguments = ["test/behat/one.feature:3", "--name", "A scenario with spaces"];
		[$status] = $this->runRunner($arguments);
		self::assertSame(0, $status);
		$calls = file($this->directory . "/test/behat/calls");
		self::assertCount(1, $calls);
		self::assertSame($arguments, json_decode($calls[0], true));
	}

	public function testExternalBrowser_usesOneRunner():void {
		[$status] = $this->runRunner(environment: ["BEHAT_CHROME_API_URL" => "http://127.0.0.1:9222"]);
		self::assertSame(0, $status);
		self::assertCount(1, file($this->directory . "/test/behat/calls"));
	}
}
