<?php
use Authwave\Model\Application;
use GT\DomTemplate\Binder;

function go(Binder $binder, Application $application):void {
	$binder->bindKeyValue("currentName", $application->name);
}
