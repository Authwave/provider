<?php
use Authwave\Admin\DemoWorkspace;
use Authwave\View\AdminView;
use Gt\Dom\HTMLDocument;
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;

function go(HTMLDocument $document, Binder $binder, Input $input, DemoWorkspace $workspace, Uri $uri):void {
	$view = new AdminView($document, $binder, $input, $workspace, $uri);
	$view->bindSettings($workspace->settings());
	$settings = $workspace->settings();
	$view->keys(["securityScore" => (7 + ($settings["securityKey"] === "yes" ? 1 : 0) + ((int)$settings["sessionTimeout"] <= 240 ? 1 : 0)) . " / 10", "sessionStatus" => $settings["sessionsRevoked"] === "yes" ? "0 active demo sessions" : count($workspace->applications()) * 21 . " active demo sessions", "auditSearch" => $input->getString("auditSearch") ?? ""]);
	$events = $audit = [];
	foreach($workspace->applications() as $app) {
		foreach([["09:42", "Repeated password guesses", "Blocked"], ["08:17", "Unusual sign-in location", "MFA required"], ["Yesterday", "Expired security code", "Rejected"]] as [$time, $description, $result]) $events[] = ["eventTime" => $time, "eventApplication" => $app["name"], "eventDescription" => $description, "eventResult" => $result];
		foreach([["09:50", "admin@example.test", "Updated password rules"], ["09:42", "s••••@example.test", "Sign-in prevented"], ["08:17", "a••••@example.test", "MFA verified"]] as [$time, $actor, $action]) {
			$search = strtolower($input->getString("auditSearch") ?? "");
			if(!$search || str_contains(strtolower("$actor $action"), $search)) $audit[] = ["auditTime" => $time, "auditActor" => $actor, "auditAction" => $action, "auditApplication" => $app["name"]];
		}
	}
	$view->list("security-events", $events);
	$view->list("audit", $audit);
	$devices = [];
	if($settings["sessionsRevoked"] !== "yes" && $workspace->applications()) foreach(["desktop" => "Firefox on Linux", "phone" => "Safari on iPhone", "tablet" => "Chrome on tablet"] as $id => $name) {
		if(!($workspace->organisation()["revokedDevices"][$workspace->applicationId . ":$id"] ?? false)) $devices[] = ["deviceId" => $id, "deviceName" => $name, "deviceDescription" => "s••••@example.test · Last used today"];
	}
	$view->list("devices", $devices);
	$view->forms();
}
