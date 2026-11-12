<?php
use Authwave\Admin\Application\ApplicationSettingsAction;
use Authwave\Admin\Onboarding\AdminArea;
use Authwave\Model\{Application, ApplicationDeployment, ApplicationRepository};
use Authwave\Session\FlashSession;
use Authwave\Security\{DomainVerification, Audit, Action};
use GT\DomTemplate\Binder;
use GT\Config\Config;
use Authwave\Model\EmailSettings;
use GT\Input\Input;
use GT\Dom\Element;
use GT\DomTemplate\ListElementCollection;
use GT\Http\Request;
use GT\Http\Response;
use GT\Http\ResponseStatusException\ClientError\HttpNotFound;

function go(Binder $binder, Application $application, ApplicationRepository $applications,
	AdminArea $area, Input $input, Request $request, FlashSession $flash, DomainVerification $domains,
	Response $response, Audit $audit, Config $config):void {
	$deployments = $applications->deployments($area->administrator(), $application->id);
	$ids = array_map(fn(ApplicationDeployment $deployment):string => $deployment->id, $deployments);
	$verify = $input->get("verify", Input::DATA_QUERYSTRING);

	if($verify !== null && $request->getMethod() === "GET") {
		if(!is_string($verify) || !in_array($verify, $ids, true)) {
			$audit->create(Action::DOMAIN_VERIFICATION_CHECKED,
				["applicationId" => $application->id, "result" => "rejected"], $area->administrator());
			throw new HttpNotFound();
		}

		if($applications->verifyDeployment($area->administrator(), $application->id, $verify)) {
			$response->redirect($request->getUri()->withoutQueryValue("verify"));
			return;
		}
	}

	$binder->bindKeyValue("hasDeployments", count($deployments) > 0);
	$binder->bindKeyValue("success", $request->getMethod() === "GET"
		? $flash->getFlash("deployment_success") ?? "" : "");
	$submitted = $request->getMethod() === "POST"
		&& $input->get("do", Input::DATA_BODY) === "save-deployment";
	$error = $submitted ? $flash->getFlash("deployment_error") ?? "" : "";
	$submittedId = $input->get("id", Input::DATA_BODY);

	if(!in_array($submittedId, $ids, true)) {
		$submittedId = "";
	}

	$opened = $input->get("deployment", Input::DATA_QUERYSTRING);
	$deployments []= new ApplicationDeployment("", $application, "", "", "", "/", emailPrefix: "security");
	$replyToConfigured = $application->emailSettings?->replyTo ?? $config->getString("email.reply_to");

	$binder->bindListCallback($deployments, function(Element $item, array $fields)use(
		$binder, $input, $submitted, $submittedId, $error, $opened, $domains, $application, $request, $verify, $replyToConfigured,
	):array {
		$id = $fields["id"];
		$isNew = $id === "";
		$local = !$isNew && $domains->isLocal($fields["providerHost"]);
		$verified = (bool)$fields["domainVerified"];
		$checking = $verify === $id && !$local && !$verified;
		$status = $verified ? "Domain verified" : "Domain not verified";

		if($checking) {
			$status = "Checking domain...";
			$region = $item->querySelector(".domain-verification");
			$region->setAttribute("data-flux", "live");
			$region->setAttribute("data-flux-rate", "10");
		}

		if($local) {
			$status = "Local domain";
		}

		if($isNew) {
			$status = "Save this deployment to verify its domain.";
		}

		$binder->bindKeyValue("isLocal", $local, $item);
		$binder->bindKeyValue("isProduction", !$isNew && $application->productionApplicationDeploymentId === $id, $item);
		$binder->bindKeyValue("verificationId", "domain-status-" . ($isNew ? "new" : $id), $item);
		$binder->bindKeyValue("verificationHelpId", "domain-help-" . ($isNew ? "new" : $id), $item);
		$binder->bindKeyValue("verificationFormId", "domain-check-" . ($isNew ? "new" : $id), $item);
		$binder->bindKeyValue("domainStatus", $status, $item);
		$binder->bindKeyValue("canVerify", !$isNew && !$local && !$verified && !$checking, $item);
		$binder->bindKeyValue("checking", $checking, $item);
		$binder->bindKeyValue("verified", $verified && !$local && !$isNew, $item);
		$binder->bindKeyValue("hostname", $domains->hostname($fields["providerHost"]), $item);
		$binder->bindKeyValue("providerMessage", $domains->hostname($request->getUri()->getHost()) === "login.authwave.com"
			? "You are using the hosted Authwave provider" : "You are self-hosting the Authwave provider", $item);

		$isSubmitted = $submitted && $submittedId === $id;
		$binder->bindKeyValue("isNew", $isNew, $item);
		$binder->bindKeyValue("isOpen", $isSubmitted || (!$submitted && $opened === $id), $item);
		$binder->bindKeyValue("panelId", "deployment-" . ($isNew ? "new" : $id), $item);
		$binder->bindKeyValue("summary", $isNew ? "Create a deployment" : $fields["title"], $item);
		$binder->bindKeyValue("submitLabel", $isNew ? "Create deployment" : "Save deployment", $item);
		$binder->bindKeyValue("apiKey", $isNew ? "" : $id . "_" . $fields["secret"], $item);
		$binder->bindKeyValue("deleteName", $fields["title"], $item);
		$binder->bindKeyValue("error", $isSubmitted ? $error : "", $item);

		if($isSubmitted) {
			foreach($item->querySelector("form.form-fields")->querySelectorAll("input[name]:not([readonly])") as $field) {
				$name = $field->getAttribute("name");
				$value = $input->get($name, Input::DATA_BODY);
				$fields[$name] = is_string($value) ? $value : "";
			}
		}

		$binder->bindKeyValue("senderInputId", "sender-prefix-" . ($isNew ? "new" : $id), $item);
		$binder->bindKeyValue("senderHelpId", "sender-help-" . ($isNew ? "new" : $id), $item);
		$binder->bindKeyValue("senderDomain", "@" . ($domains->hostname($fields["providerHost"]) ?: "login.example.com"), $item);
		$settings = new EmailSettings(null, null, null, null, $replyToConfigured);
		$binder->bindKeyValue("replyToDefault", $settings->getReplyTo(preg_match('/^[A-Za-z0-9]+(?:[.\-][A-Za-z0-9]+)*$/',
			$domains->hostname($fields["providerHost"])) ? $domains->hostname($fields["providerHost"]) : "") ?? "", $item);
		$binder->bindKeyValue("replyToConfigured", $replyToConfigured ?? "", $item);

		return $fields;
	}, templateName: "deployment-panels");
}

function do_save_deployment(ApplicationSettingsAction $action, Input $input,
	Request $request, Response $response, Element $element, ListElementCollection $templates):void {
	$form = $templates->get($element, "deployment-panels")->getClone()->querySelector("form");
	$action->saveDeployment($input, $request, $response, $form);
}

function do_delete_deployment(ApplicationSettingsAction $action, Input $input,
	Request $request, Response $response):void {
	$action->deleteDeployment($input, $request, $response);
}
