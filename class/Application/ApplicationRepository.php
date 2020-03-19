<?php
namespace Authwave\Application;

use Gt\Database\Query\QueryCollection;

class ApplicationRepository {
	private QueryCollection $db;

	public function __construct(QueryCollection $db) {
		$this->db = $db;
	}

	public function getApplicationByHost(string $host):ApplicationDeployment {
		$row = $this->db->fetch(
			"getApplicationByClientLoginHost",
			$host
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