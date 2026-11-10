<?php
use Authwave\Model\ApplicationRepository;
use Authwave\Admin\Application\ApplicationName;
use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Security\{Audit, Action};
use Authwave\Session\FlashSession;
use GT\DomTemplate\Binder;
use GT\Input\Input;
use GT\Http\Request;
use GT\Http\Response;

function go(Binder $binder, FlashSession $flash, Request $request):void {
	if($request->getMethod() !== "GET") {
		return;
	}

	$binder->bindKeyValue("success", $flash->getFlash("application_success") ?? "");
	$binder->bindKeyValue("error", $flash->getFlash("application_error") ?? "");
	$binder->bindKeyValue("name", $flash->getFlash("application_name") ?? "");
}

function do_create(ApplicationRepository $applications, AdminArea $area, FlashSession $flash,
	Audit $audit, Input $input, Response $response):void {
	$administrator = $area->administrator();
	$applicationName = $input->get("name", Input::DATA_BODY);

	try {
		$name = new ApplicationName($applicationName);
	}
	catch(\InvalidArgumentException $error) {
		$audit->create(Action::APPLICATION_CREATION_REJECTED,
			["reason" => $error->getMessage()], $administrator);
		$flash->setFlash($error->getMessage(), "application_error");
		$flash->setFlash(is_string($applicationName) ? mb_substr($applicationName, 0, 129) : "", "application_name");
		$response->redirect("/admin/applications/");
		return;
	}

	$created = $applications->create($administrator, $name);
	$flash->setFlash("Application created.", "application_success");
	$response->redirect($created->settingsUrl);
}
