<?php
use Authwave\Session\LoginSession;
use Authwave\User\LoginState;
use Authwave\View\LoginBranding;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\Binder;

function go(
	HTMLDocument $document,
	Binder $binder,
	LoginSession $loginSession,
):void {
	$document->body->classList->add("dir--login");
	$deployment = $loginSession->getDeployment();
	(new LoginBranding())->apply($document, $binder, $deployment);
	$loggedIn = $loginSession->getState() === LoginState::LOGGED_IN;
	$binder->bindKeyValue("accessTitle", $loggedIn ? "You're signed in" : "Access denied");
	$binder->bindKeyValue("accessMessage", $loggedIn
		? "This account doesn't have access to user administration."
		: "You don't have permission to access this page.");
	$loginSession->clearAdminRequest();
	// Direct admin visits have no client IV, so start the client handshake.
	$binder->bindKeyValue("returnUri", (string)$deployment->getClientReturnUri());
}
