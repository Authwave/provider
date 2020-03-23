<?php
namespace Authwave\Application;

use Gt\Http\Uri;
use Psr\Http\Message\UriInterface;

class ApplicationDeployment {
	private Application $application;
	private int $id;
	private string $clientKey;
	private string $clientHost;
	private string $clientLoginHost;

	public function __construct(
		Application $application,
		int $id,
		string $clientKey,
		string $clientHost,
		string $clientLoginHost
	) {
		$this->application = $application;
		$this->id = $id;
		$this->clientKey = $clientKey;
		$this->clientHost = $clientHost;
		$this->clientLoginHost = $clientLoginHost;
	}

	public function getApplication():Application {
		return $this->application;
	}

	public function getId():int {
		return $this->id;
	}

	public function getClientHost():UriInterface {
		$uri = new Uri($this->clientHost);
		if($uri->getScheme() === "") {
			$uri = $uri->withScheme("https");
		}

		return $uri;
	}
}