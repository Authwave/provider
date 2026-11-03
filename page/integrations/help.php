<?php
use Authwave\Admin\Onboarding\AdminArea;
use GT\Dom\HTMLDocument;
use GT\DomTemplate\Binder;
use Gt\Http\Response;
use Gt\Http\Uri;

function go(AdminArea $area, Uri $uri, Response $response, HTMLDocument $document, Binder $binder):void {
	$area->enter($uri, $response);
	$document->body->classList->add("dir--admin");
	$binder->bindKeyValue("title", "Set up Authwave");
}
