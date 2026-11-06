<?php
use Authwave\Admin\Application\{ApplicationSettingsAction, ApplicationOption};
use Authwave\Model\{Application, ApplicationRepository};
use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Session\FlashSession;
use GT\DomTemplate\Binder;
use GT\Input\Input;
use Gt\Http\{Request, Response};

function go(Binder $binder, Application $application, ApplicationRepository $applications,
	AdminArea $area, FlashSession $flash, Request $request):void {
	$binder->bindData($application);
	$binder->bindKeyValue("url", "/admin/applications/" . rawurlencode($application->id) . "/");
	$deployments = $applications->deployments($area->administrator(), $application->id);
	$options = [];

	if($deployments) {
		$options []= new ApplicationOption("", "None", $application->productionApplicationDeploymentId === null);
	}

	foreach($deployments as $deployment) {
		$options []= new ApplicationOption($deployment->id, $deployment->title,
			$deployment->id === $application->productionApplicationDeploymentId);
	}

	$binder->bindList($options, templateName: "production-options");

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
