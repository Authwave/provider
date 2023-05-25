<?php
namespace Authwave\Model;

use Gt\Database\Query\QueryCollection;
use Gt\Database\Result\Row;

class ApplicationRepository {
	public function __construct(
		private readonly QueryCollection $db
	) {
	}

	public function getById(string $id):?Application {
		return $this->rowToApplication($this->db->fetch("getById", $id));
	}

	public function getDeploymentById(string $deploymentId):ApplicationDeployment {
		$deployment = $this->rowToApplicationDeployment(
			$this->db->fetch("getDeploymentById", $deploymentId)
		);
		if(!$deployment) {
			throw new ApplicationDeploymentNotFoundException($deploymentId);
		}

		return $deployment;
	}

	public function getDeploymentByClientHost(string $clientHost):ApplicationDeployment {
		$deployment = $this->rowToApplicationDeployment(
			$this->db->fetch("getDeploymentByClientHost", $clientHost)
		);
		if(!$deployment) {
			throw new ApplicationDeploymentNotFoundException($clientHost);
		}

		return $deployment;
	}

	private function rowToApplication(?Row $row):?Application {
		if(!$row) {
			return null;
		}

		return new Application(
			$row->getString("applicationId"),
			$row->getString("name"),
		);
	}

	private function rowToApplicationDeployment(
		?Row $row
	):?ApplicationDeployment {
		if(!$row) {
			return null;
		}

		$application = $this->rowToApplication($row);

		return new ApplicationDeployment(
			$row->getString("applicationDeploymentId"),
			$application,
			$row->getString("title"),
			$row->getString("secret"),
			$row->getString("clientHost"),
			$row->getString("clientLoginPath"),
		);
	}
}
