<?php
use Authwave\Admin\Onboarding\Onboarding;
use GT\DomTemplate\Binder;

function go(Binder $binder, Onboarding $onboarding):void {
	$binder->bindKeyValue("needsApplication", $onboarding->needsApplication());
}
