<?php

use Behat\MinkExtension\Context\MinkContext;
use PHPUnit\Framework\Assert;

class FeatureContext extends MinkContext {
	/**
	 * @Given /^I should see a button labelled "([^"]*)"$/
	 */
	public function iShouldSeeAButtonLabelled(string $buttonLabelText) {
		$this->assertSession()->elementExists(
			"named",
			["button", $buttonLabelText]
		);
	}

	/**
	 * @Given /^I should see an input labelled "([^"]*)"$/
	 */
	public function iShouldSeeAnInputLabelled(string $inputLabelText) {
		$labelledInputs = $this->getSession()->getPage()->findAll(
			"css",
			"label input"
		);

		$found = null;

		foreach($labelledInputs as $input) {
			do {
				/** @var \Behat\Mink\Element\NodeElement $label */
				$label = $input->getParent();
			}
			while($label->getTagName() !== "label");

			if(trim($label->getText()) !== $inputLabelText) {
				continue;
			}

			$found = $inputLabelText;
		}

		Assert::assertNotNull($found);
	}

	/**
	 * @Given /^I should see a link labelled "([^"]*)"$/
	 */
	public function iShouldSeeALinkLabelled(string $linkLabelText) {
		$this->assertSession()->elementContains(
			"css",
			"a",
			$linkLabelText
		);
	}
}