<?php

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\OutlineNode;
use Behat\Gherkin\Node\NamedScenarioInterface;
use Behat\Hook\AfterStep;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Testwork\Tester\Result\TestResult;

/** Sends progress separately from Behat's buffered, coloured transcript. */
class ProgressContext implements Context {
	#[BeforeScenario]
	public function scenario(BeforeScenarioScope $scope):void {
		if(!getenv("BEHAT_PROGRESS_FILE")) {
			return;
		}
		$scenarios = [];
		foreach($scope->getFeature()->getScenarios() as $scenario) {
			array_push($scenarios, ...($scenario instanceof OutlineNode ? $scenario->getExamples() : [$scenario]));
		}
		$position = 0;
		foreach($scenarios as $index => $scenario) {
			if($scenario->getLine() === $scope->getScenario()->getLine()) {
				$position = $index + 1;
				break;
			}
		}
		$this->send([
			"type" => "scenario", "feature" => $scope->getFeature()->getFile(),
			"scenario" => $position, "scenarios" => count($scenarios),
			"title" => $scope->getScenario() instanceof NamedScenarioInterface
				? $scope->getScenario()->getName() : $scope->getScenario()->getTitle(),
		]);
	}

	#[AfterStep]
	public function step(AfterStepScope $scope):void {
		if($scope->getTestResult()->getResultCode() === TestResult::SKIPPED) {
			return;
		}
		$this->send(["type" => "step", "time" => microtime(true),
			"title" => $scope->getStep()->getType() . " " . $scope->getStep()->getText()]);
	}

	#[AfterScenario]
	public function finished(AfterScenarioScope $scope):void {
		$this->send(["type" => "scenario-finished"]);
	}

	private function send(array $event):void {
		if($path = getenv("BEHAT_PROGRESS_FILE")) {
			file_put_contents($path, json_encode($event, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
		}
	}
}
