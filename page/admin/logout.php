<?php
use Authwave\Admin\DemoWorkspace;
use Authwave\View\AdminView;
use Authwave\Session\LoginSession;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;

function go(HTMLDocument $document, Binder $binder, Input $input, DemoWorkspace $workspace, Uri $uri):void {
	$view = new AdminView($document, $binder, $input, $workspace, $uri);
	$view->bindSettings($workspace->settings());
	$view->forms();
}

function do_logout(
	\Gt\Http\Request $request,
	\Gt\Http\Response $response,
	\Gt\Session\Session $session,
	\Gt\Config\Config $config,
	LoginSession $loginSession,
	\Authwave\User\UserRepository $userRepo,
	\Authwave\Security\AdminAccess $adminAccess,
):void {
	\Authwave\Admin\LogoutAction::submit($request, $response, $session, $config, $loginSession, $userRepo, $adminAccess);
}
