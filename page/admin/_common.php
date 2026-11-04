<?php
use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Admin\Application\ApplicationCatalogue;
use GT\DomTemplate\Binder;
use Gt\Http\Response;
use Gt\Http\Uri;
use Authwave\Security\Sudo;
use GT\Dom\HTMLDocument;

function go(
	AdminArea $area,
	Uri $uri,
	Response $response,
	Binder $binder,
):void {
	$area->enter($uri, $response);
	$binder->bindKeyValue("title", "User Administration");
}

function go_after(Binder $binder, Uri $uri, ApplicationCatalogue $applications,
	HTMLDocument $document, Sudo $sudo, AdminArea $area, Response $response):void {
	// Sensitive values must be removed before the document can leave the server.
	$sudo->apply($document, $area->administrator(), $uri);
	$response->headers->set("Cache-Control", "no-store");
	$response->headers->set("Referrer-Policy", "same-origin");

	$page = basename(rtrim($uri->getPath(), "/"));

	if(str_starts_with($uri->getPath(), "/admin/applications/")) {
		$page = "applications";
	}

	$title = $page === "admin" ? "Dashboard" : ucfirst($page);
	$binder->bindKeyValue("title", "$title - Administration");
	$binder->bindKeyValue("scopeLabel", $applications->selected()?->name ?? "No applications");
}
