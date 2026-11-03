<?php
use Authwave\Admin\Application\{ApplicationSettingsContext, ApplicationSettingsAction};
use GT\DomTemplate\Binder;
use GT\Input\Input;
use Gt\Http\{Request, Response};

function go(Binder $binder, ApplicationSettingsContext $context, Input $input):void {
	$identifier = $input->get("confirm-delete", Input::DATA_QUERYSTRING);
	$prompt = $context->deletionPrompt(is_string($identifier) ? $identifier : null);
	$binder->bindKeyValue("showConfirmation", $prompt !== null);

	if($prompt) {
		$binder->bindData($prompt);
	}
}

function do_delete_deployment(ApplicationSettingsAction $action, Input $input,
	Request $request, Response $response):void {
	$action->deleteDeployment($input, $request, $response);
}
