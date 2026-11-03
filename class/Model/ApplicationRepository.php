<?php
namespace Authwave\Model;

use Authwave\Security\Action;
use Authwave\Security\AnonUser;
use Authwave\Security\Audit;
use Gt\Database\Database;
use Throwable;
use Authwave\Model\User;
use Authwave\Admin\Application\{ApplicationName, ApplicationSettings, DeploymentData,
	ManagedApplication, ManagedDeployment};
use Closure;
use GT\Ulid\Ulid;
use InvalidArgumentException;
use UnexpectedValueException;
use Gt\Http\ResponseStatusException\ClientError\HttpNotFound;
use Gt\Database\Result\Row;
use Gt\Http\Uri;

readonly class ApplicationRepository {
	public function __construct(
		private Database $database,
		private Audit $audit,
	) {
	}

	/** Retains the public id field and named parameter contract.
	* @SuppressWarnings("PHPMD.ShortVariable")
	*/
	public function getById(?string $id):?Application {
		if(!$id) {
			return null;
		}

		return $this->rowToApplication(
			$this->database->queryCollection("application")->fetch("getById", $id),
		);
	}

	public function getDeploymentById(?string $deploymentId):?ApplicationDeployment {
		if(!$deploymentId) {
			return null;
		}

		return $this->rowToApplicationDeployment(
			$this->database->queryCollection("application_deployment")
				->fetch("getById", $deploymentId)
		);
	}

	public function getDeploymentByProviderHost(string $host):?ApplicationDeployment {
		$deploymentId = $this->database->queryCollection("application_deployment")
			->fetchString("getIdByProviderHost", $host);

		return $this->getDeploymentById($deploymentId);
	}

	public function getClientRedirectUri(ApplicationDeployment $deployment):Uri {
		return new Uri($deployment->clientHost . $deployment->clientLoginPath);
	}

	public function getDeploymentByClientHost(string $clientHost):?ApplicationDeployment {
		$deploymentId = $this->database->queryCollection("application_deployment")
			->fetchString("getIdByClientHost", $clientHost);

		return $this->getDeploymentById($deploymentId);
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
				$themes []= ApplicationTheme::fromJson($scheme, $json, $row->getString("applicationId"));
			}
		}

		return new Application(
			$row->getString("applicationId"),
			$row->getString("name"),
			$row->getString("emailSendFrom"),
			$emailSettings,
			$themes,
			$row->getString("productionApplicationDeploymentId"),
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

	public function create(User $administrator, ApplicationName $name):ManagedApplication {
		if(trim($administrator->id) === "") {
			throw new InvalidArgumentException("An administrator identity is required.");
		}

		$application = new ManagedApplication((string)new Ulid("APPLICATION"), $name->value);
		return $this->transaction(function()use(
			$administrator, $application,
		):ManagedApplication {
			$this->database->queryCollection("application")->insert(
				"create", $application->identifier, $application->name,
			);
			$this->database->queryCollection("user_admin")->insert(
				"assign", $administrator->id, $application->identifier,
			);
			$this->audit->recordChange(
				Action::APPLICATION_CREATED,
				before: null,
				after: (object)[
					"applicationId" => $application->identifier,
					"name" => $application->name,
				],
				actor: $administrator,
			);
			return $application;
		});
	}

	/** @return list<ManagedApplication> */
	public function allFor(User $administrator):array {
		$applications = [];

		foreach($this->database->queryCollection("application")
			->fetchAll("getByAdministrator", $administrator->id) as $row) {
			$applications []= new ManagedApplication(
				$row->getString("applicationId"), $row->getString("name"),
				$row->getString("client"), $row->getString("loginHost"),
			);
		}

		return $applications;
	}

	public function get(User $actor, string $identifier):ApplicationSettings {
		$row = $this->database->queryCollection("admin_application")
			->fetch("getOwned", $identifier, $actor->id);

		if(!$row) {
			throw new HttpNotFound();
		}

		return new ApplicationSettings($row->getString("id"), $row->getString("name"),
			$row->getString("productionApplicationDeploymentId"));
	}

	/** @return list<ManagedDeployment> */
	public function deployments(User $actor, string $identifier):array {
		$this->get($actor, $identifier);
		$result = [];
		$rows = $this->database->queryCollection("admin_deployment")->fetchAll("getAll", $identifier);

		foreach($rows as $row) {
			$result []= new ManagedDeployment($row->getString("id"), $row->getString("title"),
				$row->getString("providerHost"), $row->getString("clientHost"),
				$row->getString("clientLoginPath"), $row->getString("secret"));
		}

		return $result;
	}

	public function save(User $actor, string $identifier, ApplicationName $name,
		?string $production):void {
		$this->transaction(function()use($actor, $identifier, $name, $production):void {
			$before = $this->get($actor, $identifier);
			$production = $production === "" ? null : $production;

			if($production !== null) {
				$this->deployment($actor, $identifier, $production);
			}

			$this->database->queryCollection("admin_application")->update("update",
				$name->value, $production, $identifier);
			$this->audit->recordChange(
				Action::APPLICATION_UPDATED,
				before: $before,
				after: new ApplicationSettings($identifier, $name->value, $production),
				actor: $actor,
				context: ["applicationId" => $identifier],
			);
		});
	}

	public function saveDeployment(User $actor, string $applicationId, ?string $identifier,
		DeploymentData $data):ManagedDeployment {
		return $this->transaction(function()use(
			$actor, $applicationId, $identifier, $data,
		):ManagedDeployment {
			$this->get($actor, $applicationId);
			$identifier = $identifier === "" ? null : $identifier;
			$before = $identifier !== null
				? $this->deployment($actor, $applicationId, $identifier) : null;
			$deployment = new ManagedDeployment($before->identifier ?? (string)new Ulid("DEPLOYMENT"),
				$data->name, $data->host, $data->clientUrl, $data->clientLoginPath,
				$before->secret ?? base64_encode(random_bytes(24)));
			$query = $this->database->queryCollection("admin_deployment");

			if($before) {
				$query->update("update", $data->name, $data->host, $data->clientUrl,
					$data->clientLoginPath, $identifier, $applicationId);
			}
			else {
				$query->insert("create", $deployment->identifier, $applicationId, $data->name,
					$data->host, $data->clientUrl, $data->clientLoginPath, $deployment->secret);
			}

			$this->audit->recordChange(
				$before ? Action::DEPLOYMENT_UPDATED : Action::DEPLOYMENT_CREATED,
				before: $before,
				after: $deployment,
				actor: $actor,
				context: $before
					? ["applicationId" => $applicationId, "deploymentId" => $identifier]
					: ["applicationId" => $applicationId],
			);
			return $deployment;
		});
	}

	public function deleteDeployment(User $actor, string $applicationId, string $identifier):void {
		$this->transaction(function()use($actor, $applicationId, $identifier):void {
			$application = $this->get($actor, $applicationId);
			$before = $this->deployment($actor, $applicationId, $identifier);
			$query = $this->database->queryCollection("admin_deployment");

			if($query->fetchInt("countDependents", $identifier, $identifier) > 0) {
				throw new InvalidArgumentException("Deployments with users or emails cannot be deleted.");
			}

			if($application->productionDeploymentId === $identifier) {
				$this->database->queryCollection("admin_application")->update("update",
					$application->name, null, $applicationId);
				$this->audit->recordChange(
					Action::APPLICATION_UPDATED,
					before: $application,
					after: new ApplicationSettings($applicationId, $application->name, null),
					actor: $actor,
					context: ["applicationId" => $applicationId],
				);
			}

			if($query->delete("delete", $identifier, $applicationId) !== 1) {
				throw new InvalidArgumentException("Choose a deployment assigned to this application.");
			}

			$this->audit->recordChange(
				Action::DEPLOYMENT_DELETED,
				before: $before,
				after: null,
				actor: $actor,
				context: ["applicationId" => $applicationId],
			);
		});
	}

	private function deployment(User $actor, string $applicationId,
		string $identifier):ManagedDeployment {
		foreach($this->deployments($actor, $applicationId) as $deployment) {
			if($deployment->identifier === $identifier) {
				return $deployment;
			}
		}

		throw new InvalidArgumentException("Choose a deployment assigned to this application.");
	}

	public function countFor(User $administrator):int {
		if(trim($administrator->id) === "") {
			throw new InvalidArgumentException("An administrator identity is required.");
		}

		$row = $this->database->queryCollection("user_admin")
			->fetch("countApplications", $administrator->id);
		$value = $row?->getString("applicationCount");
		$count = filter_var($value, FILTER_VALIDATE_INT);

		if($count === false || $count < 0) {
			throw new UnexpectedValueException("Invalid application count returned by the database.");
		}

		return $count;
	}

	public function redirectDeployment(
		ApplicationDeployment $deployment,
		string $host,
		AnonUser $anonUser,
		Closure $redirect,
	):void {
		$redirectUri = $this->getClientRedirectUri($deployment);
		$this->audit->create(Action::CLIENT_LOGIN_REDIRECTED, [
			"deploymentId" => $deployment->id,
			"providerHost" => $host,
			"redirectTo" => (string)$redirectUri,
		], $anonUser);

		$redirect($redirectUri);
	}

	/** @template T
	 * @param callable():T $operation
	 * @return T
	 */
	private function transaction(callable $operation):mixed {
		$connection = $this->database->getDriver()->getConnection();

		$connection->beginTransaction();

		try {
			$result = $operation();
			$connection->commit();
			return $result;
		}
		catch(Throwable $error) {
			if($connection->inTransaction()) {
				$connection->rollBack();
			}

			throw $error;
		}
	}

}
