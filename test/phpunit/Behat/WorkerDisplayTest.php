<?php
namespace Authwave\Test\Behat;

use Gt\Cli\Stream;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . "/behat/WorkerDisplay.php";

class WorkerDisplayTest extends TestCase {
	public function testSingleLine_usesCarriageReturnsWithoutWorkerRowsOrCursorUp():void {
		$path = tempnam(sys_get_temp_dir(), "authwave-display-");
		try {
			$sut = new WorkerDisplay(new Stream(out: $path), true, [["one.feature"]], 2, singleLine: true);
			$sut->update(0, ["type" => "step", "time" => 1, "title" => "Then a long scenario step completes"]);
			$sut->render();
			$sut->update(0, ["type" => "scenario-finished"]);
			$sut->update(0, ["type" => "step", "time" => 2, "title" => "Then done"]);
			$sut->render();
			$output = file_get_contents($path);
			self::assertStringStartsWith("\r0% (1 worker) - Then a long scenario step completes\r50% (1 worker) - Then done", $output);
			self::assertStringEndsWith(str_repeat(" ", 24), $output);
			self::assertStringNotContainsString("Behat worker", $output);
			self::assertStringNotContainsString("\033[", $output);
			self::assertStringNotContainsString("\n", $output);
			$sut->finish();
			self::assertStringEndsWith("\n", file_get_contents($path));
		}
		finally {
			unlink($path);
		}
	}

	public function testRender_rewritesWorkerRowsAndKeepsTheNewestStepBelowThem():void {
		$path = tempnam(sys_get_temp_dir(), "authwave-display-");
		try {
			$stream = new Stream(out: $path);
			$sut = new WorkerDisplay($stream, true, [["first.feature", "second.feature"], ["third.feature"]], 4);
			$sut->render();
			$sut->update(0, ["type" => "scenario", "feature" => "second.feature", "scenario" => 2, "scenarios" => 6, "title" => "Signing in"]);
			$sut->update(0, ["type" => "scenario-finished"]);
			$sut->update(1, ["type" => "scenario-finished"]);
			$sut->update(1, ["type" => "step", "time" => 200, "title" => "Then the user is signed in"]);
			$sut->update(0, ["type" => "step", "time" => 100, "title" => "An older step"]);
			$sut->render();
			$output = file_get_contents($path);
			self::assertStringContainsString("\033[3A", $output);
			self::assertStringContainsString("F1/2 S2/6 - Signing in", $output);
			self::assertStringEndsWith("50% - Then the user is signed in\n", $output);
			self::assertStringNotContainsString("An older step", $output);
			$sut->update(0, ["type" => "scenario", "feature" => "first.feature", "scenario" => 1, "scenarios" => 3, "title" => "Signing out"]);
			$sut->complete(1, 1);
			$sut->update(0, ["type" => "scenario-finished"]);
			$sut->update(1, ["type" => "scenario-finished"]);
			$sut->render();
			$output = file_get_contents($path);
			self::assertStringContainsString("F2/2 S1/3 - Signing out", $output);
			self::assertStringContainsString("[failed]", $output);
			self::assertStringEndsWith("100% - Then the user is signed in\n", $output);
			self::assertStringNotContainsString("Latest step", $output);
		}
		finally {
			unlink($path);
		}
	}
}
