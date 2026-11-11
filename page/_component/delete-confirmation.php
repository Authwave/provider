<?php
use Authwave\Admin\Application\ApplicationSettingsAction;
use GT\DomTemplate\Binder;
use GT\Input\Input;
use GT\Http\{Request, Response};

function go(Binder $binder, ApplicationSettingsAction $action, Input $input):void {
	$identifier = $input->get("confirm-delete", Input::DATA_QUERYSTRING);
	$prompt = $action->deletionPrompt(is_string($identifier) ? $identifier : null);
	$binder->bindKeyValue("showConfirmation", $prompt !== null);

	if($prompt) {
		$binder->bindData($prompt);
	}
}

function do_delete_deployment(ApplicationSettingsAction $action, Input $input,
	Request $request, Response $response):void {
	$action->deleteDeployment($input, $request, $response);
}
