<?php
use Authwave\Admin\DemoWorkspace;
use Authwave\View\AdminView;
use Gt\Dom\Element;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;

function go(HTMLDocument $document, Binder $binder, Input $input, DemoWorkspace $workspace, Uri $uri):void {
	$view = new AdminView($document, $binder, $input, $workspace, $uri);
	$view->bindSettings($workspace->settings());
	$rows = [];
	foreach($workspace->applications() as $id => $app) {
		$deployments = $options = [];
		foreach($app["deployments"] as $deploymentId => $deployment) {
			$deployments[] = ["appId" => $id, "deploymentId" => $deploymentId, "deploymentName" => $deployment["name"], "apiKey" => $deployment["apiKey"], "deploymentHost" => $deployment["host"], "deploymentClient" => $deployment["clientUrl"], "deploymentPath" => $deployment["loginPath"]];
		}
		foreach($workspace->organisation()["applications"][$id]["deployments"] as $deploymentId => $deployment) {
			$options[] = ["productionValue" => $deploymentId, "productionLabel" => $deployment["name"], "productionSelected" => $deploymentId === $app["production"]];
		}
		$rows[] = ["appId" => $id, "appName" => $app["name"], "deployments" => $deployments, "options" => $options];
	}
	$binder->bindListCallback($rows, function(Element $element, array $row)use($binder):array {
		$binder->bindList($row["options"], $element, "production-options");
		$binder->bindList($row["deployments"], $element, "deployments");
		unset($row["options"], $row["deployments"]);
		return $row;
	}, templateName: "applications");
	$view->forms();
}
