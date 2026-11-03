<?php
use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Admin\Application\ApplicationCatalogue;
use GT\DomTemplate\Binder;
use Gt\Http\Response;
use Gt\Http\Uri;

function go(
	AdminArea $area,
	Uri $uri,
	Response $response,
	Binder $binder,
):void {
	$area->enter($uri, $response);
	$binder->bindKeyValue("title", "User Administration");
}

function go_after(Binder $binder, Uri $uri, ApplicationCatalogue $applications):void {
	$page = basename(rtrim($uri->getPath(), "/"));

	if(str_starts_with($uri->getPath(), "/admin/applications/")) {
		$page = "applications";
	}

	$title = $page === "admin" ? "Dashboard" : ucfirst($page);
	$binder->bindKeyValue("title", "$title - Administration");
	$binder->bindKeyValue("scopeLabel", $applications->selected()?->name ?? "No applications");
}
