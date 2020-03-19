<?php
namespace Authwave\Application;

use Gt\Database\Query\QueryCollection;
use Psr\Http\Message\UriInterface;

class ApplicationRepository {
	private QueryCollection $db;

	public function __construct(QueryCollection $db) {
		$this->db = $db;
	}

	public function getApplicationByHost(
		UriInterface $host
	):ApplicationDeployment {
		$row = $this->db->fetch(
			"getApplicationByClientLoginHost",
			$host->getAuthority()
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