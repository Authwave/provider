<?php
use Authwave\Crypto\ProviderUri;
use Authwave\Session\LoginSession;
use Authwave\Model\ApplicationRepository;
use Authwave\Security\{Audit, AnonUser, Action};
use Authwave\User\UserRepository;
use Authwave\UI\TableLink;
use GT\Cipher\EncryptedUri;
use GT\Cipher\Key;
use GT\Cipher\InitVector;
use GT\Config\Config;
use Gt\Http\ResponseStatusException\ClientError\HttpNotFound;
use Gt\Http\Response;
use Gt\Http\ResponseStatusException\HttpBadRequest;
use Gt\Http\Uri;
use Gt\Http\Request;
use Gt\Session\Session;
use GT\Dom\HTMLDocument;

function go_before(Audit $audit, Request $request, LoginSession $login,
	UserRepository $users, AnonUser $anonymous):void {
	// Keep an audit trail of every request.
	$audit->recordRequest($request, $login, $users, $anonymous);
}

function go(
	ApplicationRepository $applicationRepository,
	Uri $uri,
	LoginSession $loginSession,
	Session $session,
	Response $response,
	Config $config,
	HTMLDocument $document,
	Audit $audit,
	AnonUser $anonymous,
):void {
	foreach($document->querySelectorAll("table:has(a[data-table-link])") as $table) {
		new TableLink($table);
	}

	if($uri->getPath() === "/logged-out/") {
		return;
	}

	if($deploymentId = $uri->getQueryValue("dev-login")) {
		if($config->isProduction()) {
			throw new HttpNotFound();
		}

		if(!is_string($deploymentId)) {
			throw new HttpBadRequest();
		}

		$deployment = $applicationRepository->getDeploymentById($deploymentId) ?? throw new HttpNotFound();
		$loginSession->setDeploymentForLogin($deployment);
		$loginSession->resetAuthentication();
		$loginSession->setData([
			"secretIv" => (string)new InitVector(),
// Prevents automatic navigation to the client after authentication:
			"developmentLogin" => true,
		]);

		$response->redirect("/login/");
		return;
	}

	$providerUri = new ProviderUri($uri);

	if($deploymentId = $providerUri->getDeploymentId()) {
		$deployment = $applicationRepository->getDeploymentById($deploymentId) ?? throw new HttpNotFound();

		$enc = new EncryptedUri(
			$uri,
			ProviderUri::QUERY_STRING_CIPHER,
			ProviderUri::QUERY_STRING_INIT_VECTOR
		);
		$decrypted = $enc->decryptMessage(new Key($deployment->secret));
		parse_str($decrypted, $data);

		if($data["action"] === "login") {
			$loginSession->setDeploymentForLogin($deployment);
			unset($data["returnUri"], $data["action"]);

			if($returnUri = $providerUri->getReturnUri($deployment->getClientReturnUri())) {
				$data["returnUri"] = (string)$returnUri;
			}

			$loginSession->setData($data);
			$applicationRepository->recordClientConnection($deployment->id, $uri, $anonymous);
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
					new Uri($deployment->getClientReturnUri())
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

		$deployment = $applicationRepository->getDeploymentByProviderHost($host) ?? throw new HttpNotFound();

		if($uri->getPath() === "/admin" || str_starts_with($uri->getPath(), "/admin/")) {
			$loginSession->setDeploymentForLogin($deployment);
			return;
		}

		$applicationRepository->redirectDeployment(
			$deployment,
			$host,
			$anonymous,
			$response->redirect(...),
		);
	}
}
