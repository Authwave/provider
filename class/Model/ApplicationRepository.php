<?php
namespace Authwave\Model;

use Authwave\Security\Action;
use Authwave\Security\AnonUser;
use Authwave\Security\Audit;
use Gt\Database\Query\QueryCollection;
use Gt\Database\Result\Row;
use Gt\Http\Response;

class ApplicationRepository {
	public function __construct(
		private readonly QueryCollection $database,
		private readonly Audit $audit,
		private readonly AnonUser $anonUser,
	) {
	}

	/** Retains the public id field and named parameter contract.
	* @SuppressWarnings("PHPMD.ShortVariable")
	*/
	public function getById(string $id):?Application {
		return $this->rowToApplication($this->database->fetch("getById", $id));
	}

	public function getDeploymentById(string $deploymentId):ApplicationDeployment {
		$deployment = $this->rowToApplicationDeployment(
			$this->database->fetch("getDeploymentById", $deploymentId)
		);
		if(!$deployment) {
			throw new ApplicationDeploymentNotFoundException($deploymentId);
		}

		return $deployment;
	}

	public function getDeploymentByProviderHost(string $host):ApplicationDeployment {
		$deployment = $this->rowToApplicationDeployment(
			$this->database->fetch("getDeploymentByProviderHost", $host)
		);
		if(!$deployment) {
			throw new ApplicationDeploymentNotFoundException($host);
		}

		return $deployment;
	}

	public function redirectToDeployment(
		ApplicationDeployment $deployment,
		string $host,
		Response $response,
	):void {
		$redirectTo = $deployment->clientHost
			. $deployment->clientLoginPath;

		$this->audit->create(Action::CLIENT_LOGIN_REDIRECTED, [
			"deploymentId" => $deployment->id,
			"providerHost" => $host,
			"redirectTo" => $redirectTo,
		], $this->anonUser);
		$response->redirect($redirectTo);
	}

	public function getDeploymentByClientHost(string $clientHost):ApplicationDeployment {
		$deployment = $this->rowToApplicationDeployment(
			$this->database->fetch("getDeploymentByClientHost", $clientHost)
		);
		if(!$deployment) {
			throw new ApplicationDeploymentNotFoundException($clientHost);
		}

		return $deployment;
	}

	/** Uses stateless utility/framework APIs.
	* @SuppressWarnings("PHPMD.StaticAccess")
	*/
	private function rowToApplication(?Row $row):?Application {
		if(!$row) {
			return null;
		}

		$emailSettings = null;
		if($settingsJsonString = $row->getString("emailSettings")) {
			$settingsJson = json_decode($settingsJsonString, true);
			$emailSettings = new EmailSettings(
				$settingsJson["host"] ?? null,
				$settingsJson["port"] ?? null,
				$settingsJson["username"] ?? null,
				$settingsJson["password"] ?? null,
			);
		}

		$themes = [];
		foreach(["light", "dark"] as $scheme) {
			if($json = $row->getString("{$scheme}ThemeColours")) {
				$themes []= ApplicationTheme::fromJson($scheme, $json);
			}
		}

		return new Application(
			$row->getString("applicationId"),
			$row->getString("name"),
			$row->getString("emailSendFrom"),
			$emailSettings,
			$themes,
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
			$row->getString("providerHost") ?? "",
		);
	}
}
