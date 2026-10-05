<?php
use Authwave\Session\LoginSession;
use Authwave\View\LoginBranding;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\Binder;

function go(
	HTMLDocument $document,
	Binder $binder,
	LoginSession $loginSession,
):void {
	(new LoginBranding())->apply($document, $binder, $loginSession->getDeployment());
}
