<?php
use Authwave\Admin\Application\{ApplicationSettingsContext, ApplicationSettingsAction};
use Authwave\Session\FlashSession;
use GT\DomTemplate\Binder;
use GT\Input\Input;
use Gt\Http\{Request, Response};

function go(Binder $binder, ApplicationSettingsContext $context, FlashSession $flash,
	Request $request):void {
	$binder->bindData($context->application);
	$binder->bindList($context->productionOptions(), templateName: "production-options");

	if($request->getMethod() === "GET") {
		$binder->bindKeyValue("success", $flash->getFlash("settings_success")
			?? $flash->getFlash("application_success") ?? "");
		$binder->bindKeyValue("error", $flash->getFlash("settings_error") ?? "");
	}
}

function do_save_application(ApplicationSettingsAction $action, Input $input,
	Request $request, Response $response):void {
	$action->saveApplication($input, $request, $response);
}
