<?php
namespace Authwave\Page;

use Authwave\DataTransfer\RequestData;
use Gt\Http\Uri;
use Gt\WebEngine\Logic\Page;
use Psr\Http\Message\UriInterface;

class LogoutPage extends Page {
	public function go():void {
		$appId = $this->findAppId(
			$this->server->getQueryParams()["id"] ?? null,
			$this->session->get(RequestData::SESSION_REQUEST_DATA)
		);
		$this->session->kill();

		$redirectUri = $this->getRedirectUri(
			$appId,
			$this->server->getQueryParams()["redirectTo"] ?? null
		);

		if($redirectUri) {
			$this->redirect($redirectUri);
			exit;
		}
	}

	private function findAppId(
		?string $appId,
		?RequestData $requestData
	):?string {
		if(!empty($appId)) {
			return $appId;
		}

		if(!empty($requestData)) {
			return $requestData->getId();
		}

		return null;
	}

	private function getRedirectUri(
		string $appId,
		string $redirectTo = null
	):?UriInterface {
		$appBaseUri = $this->database->fetchString(
			"getBaseUriByAppId",
			$appId
		);
		if(!$appBaseUri) {
			return null;
		}

		$scheme = parse_url($appBaseUri, PHP_URL_SCHEME)
			?? "https";
		$host = parse_url($appBaseUri, PHP_URL_HOST);
		$port = parse_url($appBaseUri, PHP_URL_PORT) ?? 80;
		$path = $redirectTo ?? "/";

		return (new Uri())
			->withScheme($scheme)
			->withHost($host)
			->withPort($port)
			->withPath($path);
	}
}