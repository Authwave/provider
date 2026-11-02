<?php

use Authwave\Crypto\ProviderUri;
use Authwave\Session\LoginSession;
use Authwave\Model\ApplicationRepository;
use Gt\Cipher\EncryptedUri;
use Gt\Cipher\Key;
use Gt\Config\Config;
use Gt\Cipher\InitVector;
use Gt\Http\ResponseStatusException\ClientError\HttpNotFound;
use Gt\Http\ResponseStatusException\ClientError\HttpBadRequest;
use Gt\Http\Response;
use Gt\Http\ServerRequest;
use Gt\Dom\HTMLDocument;
use Gt\Http\Uri;
use Gt\Session\Session;

function go(
	ApplicationRepository $appRepo,
	Uri $uri,
	LoginSession $loginSession,
	Session $session,
	Response $response,
	Config $config,
):void {
	if(rtrim($uri->getPath(), "/") === "/logged-out") return;
	parse_str($uri->getQuery(), $query);
	if(isset($query["dev-login"])) {
		if($config->isProduction() || !$config->getBool("authwave.dev_login")) {
			throw new HttpNotFound();
		}

		$deploymentId = $query["dev-login"];
		if(!is_string($deploymentId) || $deploymentId === "") {
			throw new HttpBadRequest();
		}
		$deployment = $appRepo->getDeploymentById($deploymentId);
		$loginSession->setDeploymentForLogin($deployment);
		$loginSession->resetAuthentication();
		$loginSession->setData([
			"action" => "login",
			"secretIv" => (string)new InitVector(),
			"developmentLogin" => "1",
		]);
		$response->redirect("/login/");
		return;
	}

	$providerUri = new ProviderUri($uri);
	if($deploymentId = $providerUri->getDeploymentId()) {
		$deployment = $appRepo->getDeploymentById($deploymentId);

		$enc = new EncryptedUri(
			$uri,
			ProviderUri::QUERY_STRING_CIPHER,
			ProviderUri::QUERY_STRING_INIT_VECTOR
		);
		$decrypted = $enc->decryptMessage(new Key($deployment->secret));
		parse_str($decrypted, $data);

		if($data["action"] === "login") {
			$loginSession->setDeploymentForLogin($deployment);
			unset($data["returnUri"]);
			if($returnUri = $providerUri->getReturnUri($deployment->getClientReturnUri())) {
				$data["returnUri"] = (string)$returnUri;
			}
			$loginSession->setData($data);
			$response->redirect("/");
		}
		elseif($data["action"] === "logout") {
			$loginSession->clearDataForLogout($deployment);
			$session->kill();

			if(isset($data["redirectTo"])) {
				$redirectUri = new Uri($data["redirectTo"]);
				if(!$redirectUri->getHost()) {
					$clientReturnUri = new Uri($deployment->getClientReturnUri());
					$redirectUri = $clientReturnUri
						->withPath($redirectUri->getPath())
						->withQuery($redirectUri->getQuery())
						->withFragment($redirectUri->getFragment());
				}

				$response->redirect($redirectUri);
			}
			else {
				$response->redirect(
					(new Uri($deployment->getClientReturnUri()))
						->withQueryValue("do", "logout")
				);
			}
		}
	}
	elseif(!$loginSession->getDeployment()) {
		$host = $uri->getHost();
		$port = $uri->getPort();
		if($port && $port !== 443) {
			$host .= ":$port";
		}

		$deployment = $appRepo->getDeploymentByProviderHost($host);
		if($uri->getPath() === "/admin" || str_starts_with($uri->getPath(), "/admin/")) {
			$loginSession->setDeploymentForLogin($deployment);
			return;
		}
		$appRepo->redirectToDeployment($deployment, $host, $response);
	}
}
