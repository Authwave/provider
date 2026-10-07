<?php
use Authwave\Admin\DemoWorkspace;
use Authwave\Admin\DemoReport;
use Authwave\View\AdminView;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;

function go(HTMLDocument $document, Binder $binder, Input $input, DemoWorkspace $workspace, Uri $uri):void {
	$view = new AdminView($document, $binder, $input, $workspace, $uri);
	$report = new DemoReport($input, $workspace);
	$values = $report->values;
	unset($values["chartData"], $values["dateLabel"]);
	$view->keys($report->state + $values);
	foreach($document->querySelectorAll("button[data-choice]") as $button) {
		$key = $button->getAttribute("name");
		$selected = ($report->state[$key] ?? null) === $button->getAttribute("value");
		if($key === "period" && ($report->state["from"] || $report->state["to"])) $selected = false;
		$button->setAttribute("aria-pressed", $selected ? "true" : "false");
	}

	$view->keys(["hasApplications" => count($workspace->applications()) > 0]);
	$view->list("activity", $report->rows);
	$view->forms();
}
