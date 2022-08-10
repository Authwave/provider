<?php
use Authwave\Session\LoginSession;
use Authwave\Site\SiteRepository;
use Gt\Cipher\EncryptedUri;
use Gt\Http\Response;
use Gt\Http\Uri;
use Gt\Input\Input;
use Gt\Session\Session;

function go(
	SiteRepository $siteRepo,
	Uri $uri,
	Input $input,
	?LoginSession $loginSession,
	Session $session,
	Response $response,
):void {
	if($input->contains("cipher") && $input->contains("iv")) {
		$site = $siteRepo->load($input->getString("path"));

		$enc = new EncryptedUri($uri);
		$decrypted = $enc->decryptMessage($site->key);
		parse_str($decrypted, $data);
		if($data["action"] === "login") {
			$sessionStore = $session->getStore(
				LoginSession::SESSION_STORE_KEY,
				true
			);
			$sessionStore->set("site", $site);
			$sessionStore->set("data", $data);
			$response->redirect("/");
		}
	}

	if($loginSession) {
		if(!str_starts_with($uri->getPath(), "/login/")) {
			$response->redirect("/login/");
		}
	}
	else {
		if($uri->getPath() !== "/login/error/") {
			$response->redirect("/login/error/");
		}
	}
}
