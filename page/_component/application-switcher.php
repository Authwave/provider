<?php
use Authwave\Admin\DemoWorkspace;
use Authwave\View\AdminView;
use Gt\Dom\Element;
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;

function go(Element $element, Binder $binder, Input $input, DemoWorkspace $workspace, Uri $uri):void {
	$view = new AdminView($element, $binder, $input, $workspace, $uri);
	$view->keys(["hasApplications" => count($workspace->applications()) > 0]);
	$applications = [];
	foreach($workspace->data["organisations"] as $organisationId => $organisation) {
		foreach($organisation["applications"] as $applicationId => $application) {
			$applications[] = [
				"scopeValue" => DemoWorkspace::selectionValue($organisationId, $applicationId),
				"scopeTitle" => $application["name"],
				"scopeSelected" => $organisationId === $workspace->organisationId && $applicationId === $workspace->applicationId,
			];
		}
	}
	$binder->bindList($applications, templateName: "scope-applications");
	$view->forms();
}
