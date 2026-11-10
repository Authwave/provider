<?php
use Authwave\Session\LoginSession;
use Authwave\Model\ApplicationRepository;
use Authwave\Security\Action;
use Authwave\Security\Audit;
use Authwave\Security\AdminAccess;
use Authwave\User\LoginState;
use Authwave\User\UserRepository;
use GT\Cipher\InitVector;
use GT\Cipher\Key;
use GT\Cipher\Message\PlainTextMessage;
use GT\DomTemplate\Binder;
use GT\Http\Response;
use GT\Http\Request;
use GT\Http\Uri;
use GT\Input\Input;
use GT\Session\Session;
use GT\Cookie\CookieHandler;

function go(
	Input $input,
	Response $response,
	Binder $binder,
	LoginSession $loginSession,
	UserRepository $userRepo,
	Session $session,
	Audit $audit,
	AdminAccess $adminAccess,
	Request $request,
	ApplicationRepository $applications,
	CookieHandler $cookies,
):void {
	if($loginSession->getState() !== LoginState::LOGGED_IN) {
		$response->redirect("/login/");
		return;
	}

	$deployment = $loginSession->getDeployment();
	$user = $userRepo->get($deployment, $loginSession->getEmail());

	if(!$user) {
		$loginSession->clearDataForLogout($deployment);
		$response->redirect("/login/");
		return;
	}

	$isAdmin = $adminAccess->allows($user);
	$binder->bindKeyValue("isAdmin", $isAdmin);

	if($loginSession->isAdminRequested() || !$loginSession->getDataKey("secretIv")) {
		$loginSession->clearAdminRequest();
		$audit->create(Action::LOGIN_COMPLETED, ["deploymentId" => $deployment->id], $user);
		if($request->getHeaderLine("X-Authwave-Flux") === "1") {
			$binder->bindKeyValue("returnUri", "/admin/");
			$binder->bindKeyValue("continueAutomatically", true);
		}
		else {
			$response->redirect("/admin/");
		}
		return;
	}

	$secretIvB64 = $loginSession->getDataKey("secretIv");
	$secretIvB64 = strtr($secretIvB64, " ", "+");
	$secretIvBytes = base64_decode($secretIvB64);
	$secretIv = (new InitVector())->withBytes($secretIvBytes);

	$userDataMessage = new PlainTextMessage(
		json_encode([
			"id" => $user->id,
			"email" => $loginSession->getEmail(),
		]),
		$secretIv,
	);
	$requestedReturnUri = $loginSession->getDataKey("returnUri");
	$returnUri = $requestedReturnUri
		? new Uri($requestedReturnUri)
		: $deployment->getClientReturnUri();
	$cipherText = $userDataMessage->encrypt(new Key($deployment->secret));

	if(!$requestedReturnUri && ($returnQuery = $loginSession->getDataKey("returnQuery"))) {
		$returnUri = $returnUri->withQuery($returnQuery);
	}

	$queryString = $returnUri->getQuery();

	if($queryString) {
		$queryString .= "&";
	}

	$queryString .= http_build_query([
		"AUTHWAVE_RESPONSE_DATA" => (string)$cipherText,
	]);
	$returnUri = $returnUri->withQuery($queryString);

	$binder->bindKeyValue("returnUri", (string)$returnUri);
	$developmentLogin = $loginSession->getDataKey("developmentLogin");

	if($developmentLogin) {
		$audit->create(Action::LOGIN_COMPLETED, ["deploymentId" => $deployment->id], $user);
	}
	else {
		$cookieName = UserRepository::SESSION_COOKIE_PREFIX . $deployment->id;
		$clientSession = $userRepo->startSession($user, $cookies->get($cookieName)?->getValue());
		// Retain only an opaque reporting identifier after the provider login session is cleared.
		$cookies->set($cookieName, $clientSession->id, new \DateTimeImmutable("@0"),
			"", $request->getUri()->getScheme() === "https", true);
		$applications->recordClientLogin($user);
	}

	if(!$isAdmin) {
		$session->kill();
	}

	$continueAutomatically = !$isAdmin && !$input->contains("debug") && !$developmentLogin;
	$binder->bindKeyValue("continueAutomatically", $continueAutomatically);
	// Let Flux finish on this origin; the rendered link performs the browser
	// navigation to the client, which must not be followed by fetch/CORS.
	if($continueAutomatically && $request->getHeaderLine("X-Authwave-Flux") !== "1") {
		$response->redirect($returnUri);
	}
}
