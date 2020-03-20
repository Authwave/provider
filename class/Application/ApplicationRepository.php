<?php
namespace Authwave\Application;

use Gt\Database\Query\QueryCollection;
use Psr\Http\Message\UriInterface;

class ApplicationRepository {
	private QueryCollection $db;

	public function __construct(QueryCollection $db) {
		$this->db = $db;
	}

	public function getApplicationByLoginHost(
		UriInterface $host
	):ApplicationDeployment {
		$hostUriString = $host->getAuthority();
		if($host->getHost() === "localhost") {
			$hostUriString = $host->getScheme() . "://$hostUriString";
		}

		$row = $this->db->fetch(
			"getApplicationByClientLoginHost",
			$hostUriString
		);

		if(!$row) {
			throw new ApplicationNotFoundForHostException($host);
		}

		$application = new Application(
			$row->getInt("applicationId"),
			$row->getString("displayName")
		);

		return new ApplicationDeployment(
			$application,
			$row->getInt("deploymentId"),
			$row->getString("clientKey"),
			$row->getString("clientHost"),
			$row->getString("clientLoginHost")
		);
	}
}