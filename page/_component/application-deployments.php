<?php
use Authwave\Admin\Application\{ApplicationSettingsContext, ApplicationSettingsAction};
use Authwave\Session\FlashSession;
use GT\DomTemplate\Binder;
use GT\Input\Input;
use GT\Dom\Element;
use GT\DomTemplate\ListElementCollection;
use Gt\Http\{Request, Response};

function go(Binder $binder, ApplicationSettingsContext $context, Input $input,
	Request $request, FlashSession $flash):void {
	$submitted = null;
	$error = "";
	$success = "";

	if($request->getMethod() === "GET") {
		$success = $flash->getFlash("deployment_success") ?? "";
	}

	if($request->getMethod() === "POST" && $input->get("do", Input::DATA_BODY) === "save-deployment") {
		$submitted = $input;
		$error = $flash->getFlash("deployment_error") ?? "";
	}

	$binder->bindKeyValue("hasDeployments", count($context->deployments) > 0);
	$binder->bindKeyValue("success", $success);
	$binder->bindList($context->deploymentPanels($submitted, $error), templateName: "deployment-panels");
}

function do_save_deployment(ApplicationSettingsAction $action, Input $input,
	Request $request, Response $response, Element $element, ListElementCollection $templates):void {
	$form = $templates->get($element, "deployment-panels")->getClone()->querySelector("form");
	$action->saveDeployment($input, $request, $response, $form);
}

function do_delete_deployment(ApplicationSettingsAction $action, Input $input,
	Request $request, Response $response):void {
	$action->deleteDeployment($input, $request, $response);
}
