<?php
use GT\Http\Request;
use GT\Http\Response;
use GT\Http\Uri;

function go(Request $request, Response $response):void {
	$response->redirect((new Uri("/admin/emails/"))->withQuery($request->getUri()->getQuery()));
}
