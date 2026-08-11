<?php

use Authwave\Crypto\ProviderUri;
use Authwave\Session\LoginSession;
use Authwave\Model\ApplicationRepository;
use Gt\Cipher\EncryptedUri;
use Gt\Cipher\Key;
use Gt\Http\Response;
use Gt\Http\Uri;
use Gt\Session\Session;

function go(
	ApplicationRepository $appRepo,
	Uri $uri,
	LoginSession $loginSession,
	Session $session,
	Response $response,
):void {
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
		$appRepo->redirectToDeployment($deployment, $host, $response);
	}
}
