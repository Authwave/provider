<?php
use Authwave\Admin\DemoWorkspace;
use Authwave\View\AdminView;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;

function go(HTMLDocument $document, Binder $binder, Input $input, DemoWorkspace $workspace, Uri $uri):void {
	$view = new AdminView($document, $binder, $input, $workspace, $uri);
	$view->bindSettings($workspace->settings());
	$settings = $workspace->settings();
	$view->keys(["customisationScope" => "Application-specific style", "previewColours" => "--preview-primary:{$settings['primaryColour']};--preview-secondary:{$settings['secondaryColour']}"]);
	$view->list("customisation-apps", array_map(fn($id, $app) => ["appName" => $app["name"], "customiseUrl" => $workspace->url("customisation", ["application" => $id])], array_keys($workspace->organisation()["applications"]), array_values($workspace->organisation()["applications"])));
	$view->forms();
}
