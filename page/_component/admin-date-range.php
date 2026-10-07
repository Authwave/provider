<?php
use Authwave\Admin\DemoWorkspace;
use Authwave\Admin\DemoReport;
use Authwave\View\AdminView;
use Gt\Dom\Element;
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;

function go(Element $element, Binder $binder, Input $input, DemoWorkspace $workspace, Uri $uri):void {
	$view = new AdminView($element, $binder, $input, $workspace, $uri);
	$report = new DemoReport($input, $workspace);
	$summary = $element->querySelector("summary");
	if($report->state["from"] || $report->state["to"]) $summary->setAttribute("aria-current", "true");
	else $summary->removeAttribute("aria-current");
	$view->keys($report->state);
	$view->forms();
}
