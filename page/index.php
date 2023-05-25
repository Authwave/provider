<?php
use Gt\Http\Response;

function go_after(Response $response) {
	$response->redirect("/login/");
}
