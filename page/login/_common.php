<?php
use Authwave\Session\LoginSession;
use Authwave\View\LoginBranding;
use GT\Dom\HTMLDocument;
use GT\DomTemplate\Binder;

function go(
	HTMLDocument $document,
	Binder $binder,
	LoginSession $loginSession,
):void {
	(new LoginBranding())->apply($document, $binder, $loginSession->getDeployment());
}
