<?php
use Authwave\Admin\Application\ApplicationSettingsContext;
use GT\DomTemplate\Binder;

function go(Binder $binder, ApplicationSettingsContext $context):void {
	$binder->bindKeyValue("currentName", $context->application->name);
}
