<?php
use Gt\Http\Response;

function go_after(Response $response):void {
	$response->redirect("/login/");
}
