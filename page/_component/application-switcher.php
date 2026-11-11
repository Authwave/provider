<?php
use Authwave\Admin\Application\ApplicationCatalogue;
use GT\DomTemplate\Binder;

function go(Binder $binder, ApplicationCatalogue $applications):void {
	$binder->bindKeyValue("hasApplications", count($applications->all()) > 0);
	$binder->bindList($applications->options(), templateName: "scope-applications");
}
