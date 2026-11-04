<?php
use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Security\Sudo;
use GT\Http\{Request, Response};
use GT\Http\ResponseStatusException\ClientError\HttpBadRequest;
use GT\Input\Input;

function go_before(AdminArea $area, Response $response):void {
	$area->administrator();
	$response->headers->set("Cache-Control", "no-store");
}

function do_view(Sudo $sudo, AdminArea $area, Input $input, Request $request, Response $response):void {
	if($request->getMethod() !== "POST") {
		throw new HttpBadRequest();
	}

	$field = $input->get("field", Input::DATA_QUERYSTRING);
	$return = $input->get("return", Input::DATA_QUERYSTRING);
	$action = $input->get("do", Input::DATA_BODY);

	if(!is_string($field) || !is_string($return) || !is_string($action)) {
		throw new HttpBadRequest();
	}

	try {
		$response->redirect($sudo->request($area->administrator(), $field, $return, $action));
	}
	catch(InvalidArgumentException) {
		throw new HttpBadRequest();
	}
}

function do_copy(Sudo $sudo, AdminArea $area, Input $input, Request $request, Response $response):void {
	do_view($sudo, $area, $input, $request, $response);
}

function do_disable(Sudo $sudo, AdminArea $area, Input $input, Request $request, Response $response):void {
	if($request->getMethod() !== "POST") {
		throw new HttpBadRequest();
	}

	$return = $input->get("return", Input::DATA_QUERYSTRING);

	try {
		$destination = $sudo->returnUri(is_string($return) ? $return : "/admin/");
	}
	catch(InvalidArgumentException) {
		throw new HttpBadRequest();
	}

	$sudo->disable($area->administrator());
	$response->redirect($destination);
}

function go(Response $response):void {
	$response->redirect("/admin/");
}
