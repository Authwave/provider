<?php
use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Security\Sudo;
use GT\Dom\HTMLDocument;
use GT\DomTemplate\Binder;
use GT\DomValidation\Validator;
use GT\DomValidation\ValidationException;
use GT\Http\{Request, Response};
use GT\Http\ResponseStatusException\ClientError\HttpBadRequest;
use GT\Input\Input;

function go_before(AdminArea $area, Sudo $sudo, Response $response):void {
	$actor = $area->administrator();
	$response->headers->set("Cache-Control", "no-store");

	if(!$sudo->pending($actor)) {
		$response->redirect("/admin/");
	}
}

function go(AdminArea $area, Binder $binder):void {
	$binder->bindKeyValue("email", $area->administrator()->email);
}

function do_authorise(AdminArea $area, Sudo $sudo, Input $input, Request $request,
	Response $response, HTMLDocument $document, Binder $binder):void {
	if($request->getMethod() !== "POST") {
		throw new HttpBadRequest();
	}

	$password = $input->get("password", Input::DATA_BODY);
	$form = $document->querySelector("main form");
	$validator = new Validator();
	usleep(random_int(500000, 1000000));

	try {
		$validator->validate($form, ["password" => is_string($password) ? $password : ""]);
	}
	catch(ValidationException) {
		$password = "";
	}

	if($sudo->authenticate($area->administrator(), is_string($password) ? $password : "")) {
		$response->redirect($sudo->destination($area->administrator()));
		return;
	}

	$binder->bindKeyValue("error", "Your password was not accepted. Please try again.");
}

function do_cancel(AdminArea $area, Sudo $sudo, Request $request, Response $response):void {
	if($request->getMethod() !== "POST") {
		throw new HttpBadRequest();
	}

	$actor = $area->administrator();
	$destination = $sudo->destination($actor);
	$sudo->disable($actor);
	$response->redirect($destination);
}
