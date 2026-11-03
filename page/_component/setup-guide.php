<?php
use Authwave\Admin\Onboarding\Onboarding;
use GT\DomTemplate\Binder;

function go(Binder $binder, Onboarding $onboarding):void {
	$binder->bindList($onboarding->steps(), templateName: "setup-guide-steps");
}
