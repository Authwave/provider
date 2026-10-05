<?php
use Authwave\Session\LoginSession;
use Authwave\View\AdminDashboard;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\Binder;
use Gt\Input\Input;

function go(HTMLDocument $document, Binder $binder, LoginSession $loginSession, Input $input):void {
	(new AdminDashboard())->apply($document, $binder, $loginSession, $input);
}
