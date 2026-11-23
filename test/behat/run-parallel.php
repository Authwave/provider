#!/usr/bin/env php
<?php
// Full local suites use isolated Behat runners. Selections stay in one process.
$root = dirname(__DIR__, 2);
chdir($root);
require $root . "/vendor/autoload.php";
require __DIR__ . "/WorkerDisplay.php";
$runner = __DIR__ . "/run-behat";
$arguments = array_slice($argv, 1);
$workerSetting = getenv("BEHAT_WORKERS") ?: "8";
if(!ctype_digit($workerSetting) || (int)$workerSetting < 1) {
	fwrite(STDERR, "BEHAT_WORKERS must be a positive integer.\n");
	exit(2);
}
$workers = (int)$workerSetting;
// An external browser or fixed server settings belong to a single runner.
foreach(["BEHAT_CHROME_API_URL", "BEHAT_APP_PORT", "BEHAT_CHROME_PORT", "BEHAT_APP_COMMAND"] as $setting) {
	if(getenv($setting)) {
		$workers = 1;
	}
}
if($arguments || $workers === 1) {
	passthru(escapeshellarg($runner) . ($arguments ? " " . implode(" ", array_map("escapeshellarg", $arguments)) : ""), $status);
	exit($status);
}
$features = glob(__DIR__ . "/*.feature");
$workers = min($workers, count($features));
if($workers === 0) {
	fwrite(STDERR, "No Behat features found.\n");
	exit(2);
}
// Feature length is a rough estimate of work; distribute the largest files first.
$weights = [];
foreach($features as $feature) {
	$weights[$feature] = count(file($feature));
}
usort($features, fn(string $left, string $right):int => ($weights[$right] <=> $weights[$left]) ?: strcmp($left, $right));
$groups = array_fill(0, $workers, []);
$loads = array_fill(0, $workers, 0);
foreach($features as $feature) {
	$worker = array_search(min($loads), $loads, true);
	$groups[$worker] []= $feature;
	$loads[$worker] += $weights[$feature];
}
$processes = [];
$logs = [];
$progressFiles = [];
$progressStreams = [];
$results = [];
$interactiveSetting = getenv("BEHAT_INTERACTIVE");
$interactive = $interactiveSetting === false
	? stream_isatty(STDOUT) && getenv("TERM") !== "dumb"
	: $interactiveSetting === "1";
$colour = getenv("NO_COLOR") === false && ($interactive || getenv("FORCE_COLOR"));
$scenarioTotal = 0;
$parser = new Behat\Gherkin\Parser(new Behat\Gherkin\Lexer(new Behat\Gherkin\Dialect\CucumberDialectProvider()));
foreach($features as $featurePath) {
	$feature = $parser->parseFile($featurePath);
	if(getenv("BEHAT_JAVASCRIPT") !== "1") {
		$feature = (new Behat\Gherkin\Filter\TagFilter("~@javascript"))->filterFeature($feature);
	}
	foreach($feature->getScenarios() as $scenario) {
		$scenarioTotal += $scenario instanceof Behat\Gherkin\Node\OutlineNode ? count($scenario->getExamples()) : 1;
	}
}
$display = new Authwave\Test\Behat\WorkerDisplay(new Gt\Cli\Stream(), $interactive, $groups, $scenarioTotal,
	!in_array(getenv("BEHAT_PROGRESS_MODE"), ["multi-line", "plain"], true));
$status = 0;
$interrupted = false;
if(function_exists("pcntl_async_signals")) {
	pcntl_async_signals(true);
	foreach([SIGINT, SIGTERM] as $signal) {
		pcntl_signal($signal, function(int $signal)use(&$interrupted, &$status):void {
			$interrupted = true;
			$status = 128 + $signal;
		});
	}
}
try {
	foreach($groups as $worker => $group) {
		$log = tempnam(sys_get_temp_dir(), "authwave-behat-worker-");
		$logs[$worker] = $log;
		$progressFiles[$worker] = tempnam(sys_get_temp_dir(), "authwave-behat-progress-");
		$progressStreams[$worker] = fopen($progressFiles[$worker], "r");
		$environment = array_merge(getenv(), ["BEHAT_PROGRESS_FILE" => $progressFiles[$worker]]);
		$process = proc_open([$runner, $colour ? "--colors" : "--no-colors", ...$group], [0 => STDIN, 1 => ["file", $log, "w"], 2 => ["redirect", 1]], $pipes, $root, $environment);
		if(!is_resource($process)) {
			throw new RuntimeException("Could not start Behat worker.");
		}
		$processes[$worker] = $process;
	}
	$display->render();
	while($processes && !$interrupted) {
		foreach($processes as $worker => $process) {
			$state = proc_get_status($process);
			// Clear EOF when the worker has appended more events.
			fseek($progressStreams[$worker], ftell($progressStreams[$worker]));
			while(($line = fgets($progressStreams[$worker])) !== false) {
				if(!str_ends_with($line, "\n")) {
					fseek($progressStreams[$worker], -strlen($line), SEEK_CUR);
					break;
				}
				$event = json_decode($line, true);
				if(is_array($event)) {
					$display->update($worker, $event);
				}
			}
			if($state["running"]) {
				continue;
			}
			proc_close($process);
			unset($processes[$worker]);
			$results[$worker] = $state["exitcode"];
			$display->complete($worker, $state["exitcode"]);
			if($state["exitcode"] !== 0) {
				$status = 1;
			}
		}
		$display->render();
		if($processes) {
			usleep(100000);
		}
	}
	$display->finish();
	foreach($logs as $worker => $log) {
		$result = isset($results[$worker]) ? ($results[$worker] === 0 ? "passed" : "failed") : "interrupted";
		printf("\n--- Behat worker %d/%d (%s) ---\n", $worker + 1, $workers, $result);
		readfile($log);
	}
}
finally {
	foreach($processes as $process) {
		proc_terminate($process);
	}
	foreach($processes as $process) {
		proc_close($process);
	}
	foreach($progressStreams as $stream) {
		fclose($stream);
	}
	foreach([...$logs, ...$progressFiles] as $log) {
		unlink($log);
	}
}
exit($status);
