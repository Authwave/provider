<?php
use Authwave\Session\LoginSession;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\Binder;

function go(
	HTMLDocument $document,
	Binder $binder,
	LoginSession $loginSession,
):void {
	$deployment = $loginSession->getDeployment();
	$logoFilePath = "data/upload/{$deployment->application->id}/logo.svg";

	$binder->bindKeyValue("title", "$deployment->title - Login");
	$binder->bindKeyValue("applicationName", $deployment->title);

	if(is_file($logoFilePath)) {
		$binder->bindKeyValue("logoPath", "/$logoFilePath");
	}
}
