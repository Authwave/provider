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
	$view->keys($report->state);
	foreach($element->querySelectorAll("select[data-state]") as $select) {
		foreach($select->querySelectorAll("option") as $option) $option->toggleAttribute("selected", $option->value === $report->state[$select->name]);
	}
	$view->forms();
}
