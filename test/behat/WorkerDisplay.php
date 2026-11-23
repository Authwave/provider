<?php
namespace Authwave\Test\Behat;

use Gt\Cli\Stream;
use Symfony\Component\Console\Terminal;

class WorkerDisplay {
	private array $rows = [];
	private string $latestStep = "Waiting for the first step...";
	private float $latestTime = 0;
	private array $rendered = [];
	private bool $drawn = false;
	private int $completedScenarios = 0;
	private int $singleLineWidth = 0;

	public function __construct(private Stream $stream, private bool $interactive, array $groups, private int $scenarioTotal = 0,
		private bool $singleLine = false) {
		foreach($groups as $group) {
			$this->rows []= ["total" => count($group), "features" => [], "feature" => 0,
				"scenario" => 0, "scenarios" => 0, "title" => "Starting...", "result" => ""];
		}
	}

	public function update(int $worker, array $event):void {
		if($event["type"] === "scenario") {
			$row = &$this->rows[$worker];
			if(!in_array($event["feature"], $row["features"], true)) {
				$row["features"] []= $event["feature"];
			}
			$row["feature"] = count($row["features"]);
			foreach(["scenario", "scenarios", "title"] as $key) {
				$row[$key] = $event[$key];
			}
		}
		elseif($event["type"] === "step" && $event["time"] >= $this->latestTime) {
			$this->latestTime = $event["time"];
			$this->latestStep = $event["title"];
		}
		elseif($event["type"] === "scenario-finished") {
			$this->completedScenarios++;
		}
	}

	public function complete(int $worker, int $exitCode):void {
		$this->rows[$worker]["result"] = $exitCode === 0 ? "passed" : "failed";
	}

	public function render():void {
		$lines = [];
		foreach($this->rows as $worker => $row) {
			$lines []= sprintf("Behat worker %d/%d: F%d/%d S%d/%d - %s%s", $worker + 1, count($this->rows),
				$row["feature"], $row["total"], $row["scenario"], $row["scenarios"], $row["title"],
				$row["result"] ? " [{$row['result']}]" : "");
		}
		$percentage = $this->scenarioTotal > 0
			? min(100, (int)floor(100 * $this->completedScenarios / $this->scenarioTotal)) : 0;
		$workerCount = count($this->rows);
		$workers = $this->singleLine ? " ($workerCount " . ($workerCount === 1 ? "worker" : "workers") . ")" : "";
		$lines []= "$percentage%$workers - " . $this->latestStep;
		if($lines === $this->rendered) {
			return;
		}
		if($this->singleLine) {
			$line = preg_replace('/[\x00-\x1f\x7f]/u', ' ', end($lines));
			$width = mb_strwidth($line);
			$this->stream->cursor->rewind();
			$this->stream->write($line . str_repeat(" ", max(0, $this->singleLineWidth - $width)));
			$this->singleLineWidth = $width;
			$this->rendered = $lines;
			$this->drawn = true;
			return;
		}
		if($this->interactive && $this->drawn) {
			$this->stream->cursor->moveUp(count($lines));
		}
		$width = (new Terminal())->getWidth();
		foreach($lines as $index => $line) {
			if($this->interactive) {
				$this->stream->cursor->setColumn(1);
				$this->stream->cursor->clearLine();
			}
			elseif(($this->rendered[$index] ?? null) === $line) {
				continue;
			}
			$line = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $line);
			$this->stream->write(($this->interactive ? mb_strimwidth($line, 0, max(1, $width - 1), "…") : $line) . "\n");
		}
		$this->rendered = $lines;
		$this->drawn = true;
	}

	public function finish():void {
		if($this->singleLine && $this->drawn) {
			$this->stream->write("\n");
		}
	}
}
