<?php
use Authwave\Admin\Application\ApplicationCatalogue;
use Authwave\Admin\Dashboard\ReportingPeriod;
use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Email\{EmailRepository, EmailTemplateRepository};
use GT\Http\Request;
use Authwave\Security\DomainVerification;
use Authwave\Model\{ApplicationRepository, EmailSettings};
use Authwave\Session\FlashSession;
use GT\Http\Response;
use GT\Http\Uri;
use GT\Dom\Element;
use GT\DomTemplate\Binder;
use GT\Http\ResponseStatusException\ClientError\HttpUnprocessableEntity;
use GT\Input\Input;

function go(Binder $binder, Input $input, EmailRepository $emails,
	ApplicationCatalogue $applications, ApplicationRepository $repository, AdminArea $area,
	FlashSession $flash, DomainVerification $domains, EmailTemplateRepository $templates,
	Request $request):void {
	$filters = [];
	foreach(["from" => gmdate("Y-m-d", strtotime("-29 days")), "to" => gmdate("Y-m-d"),
		"deployment" => "", "toEmail" => "", "status" => "", "subject" => "", "emailPage" => "1"] as $key => $default) {
		$value = $input->get($key, Input::DATA_QUERYSTRING) ?? $default;
		if(!is_string($value)) {
			throw new HttpUnprocessableEntity();
		}
		$filters[$key] = $value;
	}
	$applicationId = $applications->selected()->identifier;
	$actor = $area->administrator();
	$deployments = $repository->deployments($actor, $applicationId);
	bind_email_templates($binder, $input, $request, $templates, $applications, $area, $flash, $deployments);
	$senders = [];
	foreach($deployments as $deployment) {
		$address = $deployment->getSenderAddress();
		$domain = $domains->hostname($deployment->providerHost);
		$address = $address ?: "security@$domain";
		$clientDomain = strtolower(rtrim(parse_url($deployment->clientHost, PHP_URL_HOST) ?: $domain, "."));
		$defaultReplyTo = "support@" . preg_replace('/^www\./', '', $clientDomain);
		$senders[$deployment->id] = [
			"id" => $deployment->id, "label" => "$address ($deployment->title)",
			"address" => $address, "name" => $deployment->getSenderName(),
			"prefix" => $deployment->emailPrefix ?? "security", "domain" => "@$domain",
			"replyTo" => $deployment->emailReplyTo ?: $defaultReplyTo,
		];
	}
	$sender = $input->get("sender", Input::DATA_QUERYSTRING) ?? array_key_first($senders);
	if($sender !== null && (!is_string($sender) || !isset($senders[$sender]))) {
		throw new HttpUnprocessableEntity();
	}
	$binder->bindKeyValue("hasSenders", count($senders) > 0);
	$binder->bindKeyValue("senderName", $flash->getFlash("sender_name") ?? $senders[$sender]["name"] ?? "");
	$binder->bindKeyValue("senderPrefix", $flash->getFlash("sender_prefix") ?? $senders[$sender]["prefix"] ?? "");
	$binder->bindKeyValue("senderDomain", $senders[$sender]["domain"] ?? "");
	$binder->bindKeyValue("senderUrl", (string)(new Uri("/admin/emails/"))
		->withQueryValue("application", $applicationId)->withQueryValue("sender", $sender ?? "")
		->withFragment("senders"));
	$binder->bindKeyValue("senderMessage", $flash->getFlash("sender_result") ?? "");
	$binder->bindKeyValue("senderError", $flash->getFlash("sender_error") ?? "");
	$binder->bindKeyValue("senderReplyTo", $flash->getFlash("sender_replyTo") ?? $senders[$sender]["replyTo"] ?? "");
	$binder->bindListCallback(array_values($senders), function(Element $element, array $fields)use($sender):array {
		$fields["selected"] = $fields["id"] === $sender;
		return $fields;
	}, templateName: "configured-senders");
	$smtpDeployment = $input->get("smtpDeployment", Input::DATA_QUERYSTRING) ?? "";
	if(!is_string($smtpDeployment) || ($smtpDeployment !== ""
	&& !in_array($smtpDeployment, array_column($deployments, "id"), true))) {
		throw new HttpUnprocessableEntity();
	}
	$settings = $repository->emailSettings($actor, $applicationId, $smtpDeployment);
	$binder->bindKeyValue("smtpEnabled", $settings !== null);
	$binder->bindKeyValue("smtpHost", $settings?->host ?? "");
	$binder->bindKeyValue("smtpPort", $settings?->port ?? 587);
	$binder->bindKeyValue("smtpUsername", $settings?->username ?? "");
	$binder->bindKeyValue("smtpDeployment", $smtpDeployment);
	$binder->bindKeyValue("smtpApplication", $smtpDeployment === "");
	$binder->bindKeyValue("smtpUrl", (string)(new Uri("/admin/emails/"))
		->withQueryValue("application", $applicationId)
		->withQueryValue("smtpDeployment", $smtpDeployment)->withFragment("smtp"));
	$binder->bindKeyValue("smtpMessage", $flash->getFlash("smtp_result") ?? "");
	$binder->bindKeyValue("smtpError", $flash->getFlash("smtp_error") ?? "");
	$binder->bindListCallback($deployments, function(Element $element, array $fields)use($smtpDeployment):array {
		$fields["selected"] = $fields["id"] === $smtpDeployment;
		return $fields;
	}, templateName: "smtp-deployments");

	if(!ctype_digit($filters["emailPage"]) || ($filters["deployment"] !== ""
	&& !in_array($filters["deployment"], array_column($deployments, "id"), true))) {
		throw new HttpUnprocessableEntity();
	}
	try {
		$log = $emails->log($actor, $applicationId,
			new ReportingPeriod("custom", $filters["from"], $filters["to"]),
			$filters["deployment"], $filters["toEmail"], $filters["status"], (int)$filters["emailPage"], $filters["subject"]);
	}
	catch(InvalidArgumentException) {
		throw new HttpUnprocessableEntity();
	}
	foreach($filters as $key => $value) {
		$binder->bindKeyValue($key, $value);
	}
	$binder->bindKeyValue("applicationId", $applicationId);
	$binder->bindKeyValue("today", gmdate("Y-m-d"));
	$binder->bindKeyValue("allDeployments", $filters["deployment"] === "");
	$binder->bindListCallback($deployments, function(Element $element, array $fields)use($filters):array {
		$fields["selected"] = $fields["id"] === $filters["deployment"];
		return $fields;
	}, templateName: "email-deployments");
	$binder->bindListCallback($log, function(Element $element, array $fields):array {
		$fields["recipientId"] = "email-" . $fields["id"] . "-recipient";
		$fields["statusKind"] = match($fields["status"]) {
			"sent" => "success", "error" => "failed", default => "info",
		};
		$fields["statusLabel"] = ucfirst($fields["status"]);
		return $fields;
	}, templateName: "email-entries");
	$binder->bindKeyValue("hasEmails", count($log) > 0);
	$binder->bindKeyValue("emptyEmails", count($log) === 0);
	$binder->bindKeyValue("hasPagination", count($log) > 0 || $log->hasPrevious());
	$binder->bindKeyValue("hasPrevious", $log->hasPrevious());
	$binder->bindKeyValue("hasNext", $log->hasNext);
	$query = ["application" => $applicationId] + $filters;
	$query["emailPage"] = max(1, $log->page - 1);
	$binder->bindKeyValue("previousEmails", "/admin/emails/?" . http_build_query($query) . "#email-log");
	$query["emailPage"] = $log->page + 1;
	$binder->bindKeyValue("nextEmails", "/admin/emails/?" . http_build_query($query) . "#email-log");
	$binder->bindKeyValue("resetEmails", "/admin/emails/?" . http_build_query(["application" => $applicationId]) . "#email-log");
}

function do_save_smtp(Input $input, ApplicationCatalogue $applications,
	ApplicationRepository $repository, AdminArea $area, FlashSession $flash, Response $response):void {
	$deployment = $input->get("smtpDeployment", Input::DATA_QUERYSTRING) ?? "";
	$enabled = $input->get("smtpEnabled", Input::DATA_BODY);
	if(!is_string($deployment) || !in_array($enabled, [null, "yes"], true)) {
		throw new HttpUnprocessableEntity();
	}
	$applicationId = $applications->selected()->identifier;
	try {
		$settings = null;
		if($enabled === "yes") {
			$values = [];
			foreach(["smtpHost", "smtpPort", "smtpUsername", "smtpPassword"] as $name) {
				$value = $input->get($name, Input::DATA_BODY) ?? "";
				if(!is_string($value)) {
					throw new HttpUnprocessableEntity();
				}
				$values[$name] = $value;
			}
			$port = filter_var($values["smtpPort"], FILTER_VALIDATE_INT);
			$settings = new EmailSettings($values["smtpHost"], $port === false ? 0 : $port,
				$values["smtpUsername"], $values["smtpPassword"]);
		}
		$repository->saveEmailSettings($area->administrator(), $applicationId, $deployment, $settings);
		$flash->setFlash("SMTP settings saved.", "smtp_result");
	}
	catch(InvalidArgumentException $error) {
		$flash->setFlash($error->getMessage(), "smtp_error");
	}
	$response->redirect((new Uri("/admin/emails/"))->withQueryValue("application", $applicationId)
		->withQueryValue("smtpDeployment", $deployment)->withFragment("smtp"));
}

function do_save_sender(Input $input, ApplicationCatalogue $applications,
	ApplicationRepository $repository, AdminArea $area, FlashSession $flash, Response $response):void {
	$deployment = $input->get("sender", Input::DATA_QUERYSTRING);
	$prefix = $input->get("emailPrefix", Input::DATA_BODY);
	$fromName = $input->get("senderName", Input::DATA_BODY);
	$replyTo = $input->get("emailReplyTo", Input::DATA_BODY) ?? "";
	if(!is_string($deployment) || !is_string($prefix) || !is_string($replyTo) || !is_string($fromName)) {
		throw new HttpUnprocessableEntity();
	}
	$applicationId = $applications->selected()->identifier;
	try {
		$repository->saveSender($area->administrator(), $applicationId, $deployment, $prefix, $replyTo, $fromName);
		$flash->setFlash("Sender settings saved.", "sender_result");
	}
	catch(InvalidArgumentException $error) {
		$flash->setFlash($error->getMessage(), "sender_error");
		$flash->setFlash($prefix, "sender_prefix");
		$flash->setFlash($fromName, "sender_name");
		$flash->setFlash($replyTo, "sender_replyTo");
	}
	$response->redirect((new Uri("/admin/emails/"))->withQueryValue("application", $applicationId)
		->withQueryValue("sender", $deployment)->withFragment("senders"));
}

/** @param list<\Authwave\Model\ApplicationDeployment> $deployments */
function bind_email_templates(Binder $binder, Input $input, Request $request,
	EmailTemplateRepository $templates, ApplicationCatalogue $applications,
	AdminArea $area, FlashSession $flash, array $deployments):void {
	$name = $input->get("template", Input::DATA_QUERYSTRING) ?? "securityCode";
	$deployment = $input->get("templateDeployment", Input::DATA_QUERYSTRING) ?? "";
	if(!is_string($name) || !is_string($deployment)) {
		throw new HttpUnprocessableEntity();
	}
	$applicationId = $applications->selected()->identifier;
	try {
		$template = $templates->get($area->administrator(), $applicationId, $name, $deployment);
	}
	catch(InvalidArgumentException) {
		throw new HttpUnprocessableEntity();
	}
	$draft = $flash->getFlash("template_draft");
	if($draft !== null) {
		$template = json_decode($draft, true) + $template;
	}
	if($request->getMethod() === "POST" && $input->get("do", Input::DATA_BODY) === "preview_template") {
		$subject = $input->get("templateSubject", Input::DATA_BODY);
		$html = $input->get("templateContent", Input::DATA_BODY);
		if(!is_string($subject) || !is_string($html)) {
			throw new HttpUnprocessableEntity();
		}
		$template["subject"] = $subject;
		$template["html"] = $html;
	}
	$preview = "";
	$error = $flash->getFlash("template_error") ?? "";
	try {
		$preview = $templates->preview($area->administrator(), $applicationId, $name, $deployment,
			$template["subject"], $template["html"])["html"];
	}
	catch(InvalidArgumentException $exception) {
		$error = $error ?: $exception->getMessage();
	}
	$options = [];
	foreach(EmailTemplateRepository::OPTIONS as $value => $label) {
		$options []= ["value" => $value, "label" => $label, "selected" => $value === $name];
	}
	$binder->bindList($options, templateName: "email-template-options");
	$binder->bindListCallback($deployments, function(Element $element, array $fields)use($deployment):array {
		$fields["selected"] = $fields["id"] === $deployment;
		return $fields;
	}, templateName: "template-deployments");
	$binder->bindKeyValue("templateApplication", $deployment === "");
	$binder->bindKeyValue("templateSubject", $template["subject"]);
	$binder->bindKeyValue("templateContent", $template["html"]);
	$binder->bindKeyValue("templatePreview", $preview);
	$binder->bindKeyValue("templatePreviewOpen", $draft !== null || ($request->getMethod() === "POST"
		&& $input->get("do", Input::DATA_BODY) === "preview_template"));
	$binder->bindKeyValue("templateCustom", $template["custom"]);
	$binder->bindKeyValue("templateMessage", $flash->getFlash("template_result") ?? "");
	$binder->bindKeyValue("templateError", $error);
	$binder->bindKeyValue("templateUrl", (string)$request->getUri()
		->withQueryValue("application", $applicationId)->withQueryValue("template", $name)->withQueryValue("templateDeployment", $deployment)
		->withFragment("templates"));
}

function do_save_template(Input $input, Request $request, EmailTemplateRepository $templates,
	ApplicationCatalogue $applications, AdminArea $area, FlashSession $flash, Response $response):void {
	$name = $input->get("template", Input::DATA_QUERYSTRING) ?? "securityCode";
	$deployment = $input->get("templateDeployment", Input::DATA_QUERYSTRING) ?? "";
	$subject = $input->get("templateSubject", Input::DATA_BODY);
	$html = $input->get("templateContent", Input::DATA_BODY);
	if(!is_string($name) || !is_string($deployment) || !is_string($subject) || !is_string($html)) {
		throw new HttpUnprocessableEntity();
	}
	try {
		$templates->save($area->administrator(), $applications->selected()->identifier,
			$name, $deployment, $subject, $html);
		$flash->setFlash("Email template saved.", "template_result");
	}
	catch(InvalidArgumentException $error) {
		$flash->setFlash($error->getMessage(), "template_error");
		$flash->setFlash(json_encode(["subject" => $subject, "html" => $html], JSON_THROW_ON_ERROR),
			"template_draft");
	}
	$response->redirect($request->getUri()->withFragment("templates"));
}

function do_reset_template(Input $input, Request $request, EmailTemplateRepository $templates,
	ApplicationCatalogue $applications, AdminArea $area, FlashSession $flash, Response $response):void {
	$name = $input->get("template", Input::DATA_QUERYSTRING) ?? "securityCode";
	$deployment = $input->get("templateDeployment", Input::DATA_QUERYSTRING) ?? "";
	if(!is_string($name) || !is_string($deployment)) {
		throw new HttpUnprocessableEntity();
	}
	try {
		$templates->reset($area->administrator(), $applications->selected()->identifier, $name, $deployment);
	}
	catch(InvalidArgumentException) {
		throw new HttpUnprocessableEntity();
	}
	$flash->setFlash("Default email template restored.", "template_result");
	$response->redirect($request->getUri()->withFragment("templates"));
}

function do_preview_template(Input $input, Request $request, FlashSession $flash, Response $response):void {
	if($request->getHeaderLine("X-Authwave-Flux") === "1") {
		return;
	}
	$subject = $input->get("templateSubject", Input::DATA_BODY);
	$html = $input->get("templateContent", Input::DATA_BODY);
	if(!is_string($subject) || !is_string($html)) {
		throw new HttpUnprocessableEntity();
	}
	$flash->setFlash(json_encode(["subject" => $subject, "html" => $html], JSON_THROW_ON_ERROR),
		"template_draft");
	$response->redirect($request->getUri()->withFragment("templates"));
}
