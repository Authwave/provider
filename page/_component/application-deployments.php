<?php
use Authwave\Admin\Application\{ApplicationSettingsContext, ApplicationSettingsAction};
use GT\DomTemplate\Binder;
use GT\Input\Input;
use Gt\Http\{Request, Response};

function go(Binder $binder, ApplicationSettingsContext $context):void {
	$binder->bindKeyValue("hasDeployments", count($context->deployments) > 0);
	$binder->bindList($context->deploymentPanels(), templateName: "deployment-panels");
}

function do_save_deployment(ApplicationSettingsAction $action, Input $input,
	Request $request, Response $response):void {
	$action->saveDeployment($input, $request, $response);
}

function do_delete_deployment(ApplicationSettingsAction $action, Input $input,
	Request $request, Response $response):void {
	$action->deleteDeployment($input, $request, $response);
}
