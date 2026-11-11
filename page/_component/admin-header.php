<?php
use Authwave\Admin\Application\ApplicationCatalogue;
use GT\DomTemplate\Binder;

function go(Binder $binder, ApplicationCatalogue $applications):void {
	$application = $applications->selected();
	$hasClient = $application !== null && $application->client !== "-";
	$binder->bindKeyValue("hasClient", $hasClient);
	$binder->bindKeyValue("hasApplication", $application !== null);
	$binder->bindKeyValue("applicationId", $application?->identifier ?? "");
	$binder->bindKeyValue("addUserUri", "/admin/users/?application="
		. rawurlencode($application?->identifier ?? "") . "#add-user");
	$binder->bindKeyValue("applicationName", $application?->name ?? "");
	$binder->bindKeyValue("clientUri", $hasClient ? $application->client : "");
}
