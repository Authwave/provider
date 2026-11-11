<?php
use GT\Http\Response;

function go_after(Response $response):void {
	$response->redirect("/login/");
}
