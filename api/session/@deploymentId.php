<?php
use Authwave\Model\ApplicationRepository;
use Authwave\Security\ClientSessionRequest;
use Authwave\User\UserRepository;
use GT\Http\Request;
use GT\Http\Response;
use GT\Http\ResponseStatusException\ClientError\HttpForbidden;
use GT\Http\ResponseStatusException\ClientError\HttpMethodNotAllowed;
use GT\Json\Schema\JSONDocument;
use GT\Routing\Path\DynamicPath;

function go(Request $request, Response $response, JSONDocument $document,
	DynamicPath $path, ApplicationRepository $applications, UserRepository $users):void {
	$response->headers->set("Cache-Control", "no-store");

	if($request->getMethod() !== "POST") {
		throw new HttpMethodNotAllowed();
	}

	$deployment = $applications->getDeploymentById($path->get("deploymentId"));

	if($deployment === null) {
		throw new HttpForbidden();
	}

	try {
		$message = new ClientSessionRequest((string)$request->getBody(),
			$request->getHeaderLine("Authwave-Session-Signature"), $deployment->secret);
	}
	catch(InvalidArgumentException) {
		throw new HttpForbidden();
	}

	$valid = $users->validateSession($deployment, $message->sessionId, $message->active);
	$document->set("valid", $valid);
	$response->headers->set("Authwave-Session-Signature", $message->responseSignature($valid, $deployment->secret));
}
