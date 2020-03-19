<?php
namespace Authwave\Application;

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
}