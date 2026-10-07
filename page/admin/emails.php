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
	$template = $view->choice("template", ["verify", "welcome", "security-code", "password-changed"], "security-code");
	$sender = $view->choice("sender", array_merge(["new"], array_keys($workspace->senders())), "accounts");
	$view->list("senders", array_map(fn($id, $name) => ["senderValue" => $id, "senderLabel" => $name], array_keys($workspace->senders()), array_values($workspace->senders())));
	$titles = ["verify" => "Verify your account", "welcome" => "Welcome to {{applicationName}}", "security-code" => "Your security code", "password-changed" => "Your password was changed"];
	$templateSettings = ($workspace->organisation()["settings"][$workspace->applicationId . ":template:$template"] ?? []) + ($workspace->organisation()["settings"]["all:template:$template"] ?? []) + ["templateSubject" => $titles[$template], "templateContent" => "<h1>" . $titles[$template] . "</h1><p>Hello {{email}},</p><p>Your security code is <strong>{{code}}</strong>.</p>"];
	$view->keys(["template" => $template, "sender" => $sender, "templatePreview" => strtr($templateSettings["templateContent"], ["{{applicationName}}" => "Example application", "{{email}}" => "sienna@example.test", "{{code}}" => "123456"]), "dnsStatus" => $workspace->settings()["domainVerified"] === "yes" ? "Demo domain verified · SPF and DKIM ready" : "Demo DNS: SPF ready · DKIM ready · verification pending"]);
	$view->bindSettings($templateSettings);
	$recipient = substr(trim($input->getString("recipient") ?? ""), 0, 100);
	$filterTemplate = $view->choice("deliveryTemplate", ["all", "verify", "welcome", "security-code"], "all");
	$status = $view->choice("deliveryStatus", ["all", "opened", "delivered", "bounced"], "all");
	$date = $view->date("deliveryDate")?->format("Y-m-d") ?? "";
	$view->keys(["recipient" => $recipient, "deliveryTemplate" => $filterTemplate, "deliveryStatus" => $status, "deliveryDate" => $date]);
	$rows = [];
	foreach($workspace->users() as $i => $user) {
		$type = ["security-code", "verify", "welcome"][$i % 3];
		$state = ["opened", "delivered", "bounced"][$i % 3];
		$sentDate = date("Y-m-d", strtotime("-" . ($i % 4) . " days"));
		if(($recipient && !str_contains(strtolower($user["email"]), strtolower($recipient))) || ($filterTemplate !== "all" && $type !== $filterTemplate) || ($status !== "all" && $state !== $status) || ($date && $sentDate !== $date)) continue;
		$rows[] = ["deliveryRecipient" => DemoWorkspace::email($user["email"]), "deliverySubject" => $titles[$type], "deliveryState" => ucfirst($state), "deliveryApplication" => $user["application"], "sentAt" => "Sent $sentDate 09:20", "receivedAt" => $state === "bounced" ? "Rejected by recipient server" : "Received 09:20", "openedAt" => $state === "opened" ? "Opened 09:21" : "Not opened", "deliveryHeaders" => "From: accounts@example.test\nTo: " . DemoWorkspace::email($user["email"]) . "\nMessage-ID: <demo-$i@example.test>\nX-Authwave-Application: " . $user["application"], "deliveryVariables" => json_encode(["applicationName" => $user["application"], "email" => DemoWorkspace::email($user["email"]), "code" => "123456"], JSON_PRETTY_PRINT)];
	}
	$view->list("deliveries", array_slice($rows, 0, 12));
	$view->keys(["hasDeliveries" => count($rows) > 0]);
	$senderSettings = $workspace->organisation()["settings"][$workspace->applicationId . ":sender:$sender"] ?? [];
	$senderSettings += $sender === "support" ? ["senderName" => "Support team", "senderEmail" => "support@example.test", "replyTo" => "support@example.test", "senderDomain" => "example.test"] : ($sender === "new" ? ["senderName" => "", "senderEmail" => "", "replyTo" => "", "senderDomain" => ""] : []);
	$view->bindSettings($senderSettings);
	$view->forms();
}
