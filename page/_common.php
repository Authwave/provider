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
// TODO: There may be multiple client hosts with the same value, especially when
// on localhost! The key needs to be used in this getter to avoid people being
// able to retrieve other people's deployments just by knowing the host.
		$deployment = $appRepo->getDeploymentById($deploymentId);

		$enc = new EncryptedUri(
			$uri,
			ProviderUri::QUERY_STRING_CIPHER,
			ProviderUri::QUERY_STRING_INIT_VECTOR
		);
		$decrypted = $enc->decryptMessage(new Key($deployment->secret));
		parse_str($decrypted, $data);

		if($data["action"] === "login") {
			$loginSession->setDeployment($deployment);
			$loginSession->setData($data);
			$response->redirect("/");
		}
	}
}
