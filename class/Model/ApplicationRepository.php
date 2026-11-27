<?php
namespace Authwave\Model;

use Authwave\Dns\{Hostname, Nsd};
use Authwave\Security\Action;
use Authwave\Security\AnonUser;
use Authwave\Security\Audit;
use Authwave\Security\DomainVerification;
use GT\Database\Database;
use Throwable;
use DateTimeImmutable;
use DateTimeZone;
use Authwave\Model\User;
use Authwave\Admin\Application\{ApplicationName, DeploymentData, ManagedApplication};
use Closure;
use GT\Ulid\Ulid;
use InvalidArgumentException;
use UnexpectedValueException;
use GT\Http\ResponseStatusException\ClientError\HttpNotFound;
use GT\Database\Result\Row;
use GT\Http\Uri;

readonly class ApplicationRepository {
	public function __construct(
		private Database $database,
		private Audit $audit,
		private DomainVerification $domains,
		private ?Nsd $nsd = null,
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
				$settingsJson["replyTo"] ?? null,
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
			$row->getBool("domainVerified"),
			$row->getBool("clientConnected"),
			$row->getBool("userLoggedIn"),
			$row->getString("emailPrefix"), $row->getString("emailReplyTo"),
			$row->getString("emailFromName"),
		);
	}

	public function emailSettings(User $actor, string $applicationId,
		string $deploymentId = ""):?EmailSettings {
		$application = $this->get($actor, $applicationId);
		if($deploymentId === "") {
			return $application->emailSettings;
		}
		$this->deployment($actor, $applicationId, $deploymentId);
		$row = $this->database->queryCollection("application_deployment")->fetch("getEmailSettings", [
			"deploymentId" => $deploymentId, "applicationId" => $applicationId,
		]);
		$settings = json_decode($row?->getString("emailSettings") ?? "null", true);
		return $settings === null ? null : new EmailSettings(
			$settings["host"] ?? null, $settings["port"] ?? null,
			$settings["username"] ?? null, $settings["password"] ?? null, $settings["replyTo"] ?? null);
	}

	public function saveEmailSettings(User $actor, string $applicationId,
		string $deploymentId, ?EmailSettings $settings):void {
		$this->transaction(function()use($actor, $applicationId, $deploymentId, $settings):void {
			$previous = $this->emailSettings($actor, $applicationId, $deploymentId);
			if($settings !== null) {
				$host = trim($settings->host ?? "");
				if($host === "" || strlen($host) > 253
				|| !preg_match('/^[a-zA-Z0-9._:-]+$/D', $host)
				|| ($settings->port ?? 0) < 1 || $settings->port > 65535
				|| strlen($settings->username ?? "") > 256 || strlen($settings->password ?? "") > 4096) {
					throw new InvalidArgumentException("Enter a valid SMTP host and port (1–65535).");
				}
				$settings = new EmailSettings($host, $settings->port, $settings->username,
					$settings->password === "" ? ($previous->password ?? "") : $settings->password,
					$previous?->replyTo);
			}
			$fields = ["applicationId" => $applicationId,
				"emailSettings" => $settings === null ? null : json_encode($settings, JSON_THROW_ON_ERROR)];
			$collection = "application";
			if($deploymentId !== "") {
				$collection = "application_deployment";
				$fields["deploymentId"] = $deploymentId;
			}
			$this->database->queryCollection($collection)->update("saveEmailSettings", $fields);
			$this->audit->create(Action::EMAIL_SETTINGS_UPDATED, [
				"applicationId" => $applicationId, "deploymentId" => $deploymentId ?: null,
				"customSmtp" => $settings !== null,
			], $actor);
		});
	}

	public function getSessionPolicyByApplicationId(string $applicationId):?ApplicationSessionPolicy {
		$row = $this->database->queryCollection("application_session_policy")
			->fetch("getById", $applicationId);

		return $row === null ? null : new ApplicationSessionPolicy($applicationId,
			$row->getInt("maximumLifetimeMinutes"), $row->getInt("idleTimeoutMinutes"));
	}

	public function sessionPolicy(User $actor, string $applicationId):ApplicationSessionPolicy {
		$this->get($actor, $applicationId);

		return $this->getSessionPolicyByApplicationId($applicationId)
			?? new ApplicationSessionPolicy($applicationId);
	}

	public function saveSessionPolicy(User $actor, ApplicationSessionPolicy $policy):void {
		$this->get($actor, $policy->applicationId);
		$this->transaction(function()use($actor, $policy):void {
			$previous = $this->getSessionPolicyByApplicationId($policy->applicationId);
			$fields = ["applicationId" => $policy->applicationId,
				"maximumLifetimeMinutes" => $policy->maximumLifetimeMinutes,
				"idleTimeoutMinutes" => $policy->idleTimeoutMinutes];

			if($previous === null) {
				$this->database->queryCollection("application_session_policy")->insert("create", $fields);
			}
			else {
				$this->database->queryCollection("application_session_policy")->update("update", $fields);
			}
			$now = new DateTimeImmutable("now", new DateTimeZone("UTC"));
			$rows = $this->database->queryCollection("user_session")->fetchAll("getByApplication", [
				"applicationId" => $policy->applicationId,
				"deploymentFilter" => "", "deploymentId" => "", "now" => $now,
			]);

			foreach($rows as $row) {
				if(!$row->getBool("managed")) {
					continue;
				}

				$createdAt = new DateTimeImmutable($row->getString("createdAt"), new DateTimeZone("UTC"));
				$expiresAt = new DateTimeImmutable($row->getString("expiresAt"), new DateTimeZone("UTC"));
				$this->database->queryCollection("user_session")->update("shorten", [
					"id" => $row->getString("id"),
					"expiresAt" => min($expiresAt,
						$createdAt->modify("+{$policy->maximumLifetimeMinutes} minutes")),
					"idleTimeoutMinutes" => min($row->getInt("idleTimeoutMinutes"), $policy->idleTimeoutMinutes),
				]);
			}

			$this->audit->recordChange(Action::SESSION_POLICY_UPDATED, $previous, $policy, $actor,
				["applicationId" => $policy->applicationId]);
		});
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

	public function get(User $actor, string $identifier):Application {
		$id = $this->database->queryCollection("admin_application")
			->fetchString("getOwned", $identifier, $actor->id);

		return $this->getById($id) ?? throw new HttpNotFound();
	}

	/** @return list<ApplicationDeployment> */
	public function deployments(User $actor, string $identifier):array {
		$application = $this->get($actor, $identifier);
		$result = [];
		$rows = $this->database->queryCollection("admin_deployment")->fetchAll("getAll", $identifier);

		foreach($rows as $row) {
			$result []= new ApplicationDeployment($row->getString("id"), $application,
				$row->getString("title"), $row->getString("secret"), $row->getString("clientHost"),
				$row->getString("clientLoginPath"), $row->getString("providerHost"),
				$row->getBool("domainVerified"), $row->getBool("clientConnected"),
				$row->getBool("userLoggedIn"), $row->getString("emailPrefix"), $row->getString("emailReplyTo"),
				$row->getString("emailFromName"));
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
				before: (object)["name" => $before->name,
					"productionApplicationDeploymentId" => $before->productionApplicationDeploymentId],
				after: (object)["name" => $name->value, "productionApplicationDeploymentId" => $production],
				actor: $actor,
				context: ["applicationId" => $identifier],
			);
		});
	}

	public function saveDeployment(User $actor, string $applicationId, ?string $identifier,
		DeploymentData $data, bool $preserveSender = false):ApplicationDeployment {
		[$deployment, $before] = $this->transaction(function()use(
			$actor, $applicationId, $identifier, $data, $preserveSender,
		):array {
			$application = $this->get($actor, $applicationId);
			$identifier = $identifier === "" ? null : $identifier;
			$before = $identifier !== null
				? $this->deployment($actor, $applicationId, $identifier) : null;
			if($preserveSender && $before) {
				$data = new DeploymentData($data->title, $data->providerHost,
					$data->clientHost, $data->clientLoginPath,
					$before->emailPrefix ?? "", $before->emailReplyTo ?? "");
			}
			$oldHostname = $this->domains->hostname($before->providerHost ?? "");
			$newHostname = $this->domains->hostname($data->providerHost);
			$verified = $oldHostname === $newHostname && ($before->domainVerified ?? false);
			$sameConnection = $before && $before->providerHost === $data->providerHost
				&& $before->clientHost === $data->clientHost
				&& $before->clientLoginPath === $data->clientLoginPath;
			$deployment = new ApplicationDeployment($before->id ?? (string)new Ulid("DEPLOYMENT"),
				$application, $data->title, $before->secret ?? base64_encode(random_bytes(24)),
				$data->clientHost, $data->clientLoginPath, $data->providerHost, $verified,
				$sameConnection && $before->clientConnected, $sameConnection && $before->userLoggedIn,
				$data->emailPrefix, $data->emailReplyTo, $before?->emailFromName);
			$query = $this->database->queryCollection("admin_deployment");

			if($before) {
				$query->update("update", $data->title, $data->providerHost, $data->clientHost,
					$data->clientLoginPath, (int)$deployment->domainVerified,
					(int)$deployment->clientConnected, (int)$deployment->userLoggedIn,
					$data->emailPrefix, $data->emailReplyTo,
					$identifier, $applicationId);
			}
			else {
				$query->insert("create", $deployment->id, $applicationId, $data->title,
					$data->providerHost, $data->clientHost, $data->clientLoginPath, $deployment->secret,
					$data->emailPrefix, $data->emailReplyTo);
			}

			$this->audit->recordChange(
				$before ? Action::DEPLOYMENT_UPDATED : Action::DEPLOYMENT_CREATED,
				before: $before ? $this->deploymentDetails($before) : null,
				after: $this->deploymentDetails($deployment),
				actor: $actor,
				context: $before
					? ["applicationId" => $applicationId, "deploymentId" => $identifier]
					: ["applicationId" => $applicationId],
			);
			return [$deployment, $before];
		});
		$this->nsd?->add($deployment->providerHost);
		if($before && $this->domains->hostname($before->providerHost)
		!== $this->domains->hostname($deployment->providerHost)) {
			$this->removeDnsZone($before->providerHost);
		}
		return $deployment;
	}

	public function saveSender(User $actor, string $applicationId, string $deploymentId,
		string $prefix, string $replyTo, ?string $fromName = null):void {
		$this->transaction(function()use(
			$actor, $applicationId, $deploymentId, $prefix, $replyTo, $fromName,
		):void {
			$before = $this->deployment($actor, $applicationId, $deploymentId);
			if(trim($prefix) === "") {
				throw new InvalidArgumentException("Enter a sender email prefix.");
			}
			$fromName = $fromName === null ? $before->emailFromName : trim($fromName);
			if($fromName !== null && ($fromName === "" || strlen($fromName) > 256
			|| preg_match('/\A[^\p{C}]+\z/u', $fromName) !== 1)) {
				throw new InvalidArgumentException(
					"Enter a From name of up to 256 characters without control characters.");
			}
			$data = new DeploymentData($before->title, $before->providerHost,
				$before->clientHost, $before->clientLoginPath, $prefix, $replyTo);
			$this->database->queryCollection("admin_deployment")->update("saveSender", [
				"applicationId" => $applicationId, "deploymentId" => $deploymentId,
				"emailPrefix" => $data->emailPrefix, "emailReplyTo" => $data->emailReplyTo,
				"emailFromName" => $fromName,
			]);
			$this->audit->recordChange(Action::SENDER_SETTINGS_UPDATED,
				before: (object)["emailPrefix" => $before->emailPrefix,
					"emailReplyTo" => $before->emailReplyTo, "emailFromName" => $before->emailFromName],
				after: (object)["emailPrefix" => $data->emailPrefix, "emailReplyTo" => $data->emailReplyTo,
					"emailFromName" => $fromName],
				actor: $actor, context: ["applicationId" => $applicationId, "deploymentId" => $deploymentId]);
		});
	}

	public function deleteDeployment(User $actor, string $applicationId, string $identifier):void {
		$hostname = $this->transaction(function()use($actor, $applicationId, $identifier):string {
			$application = $this->get($actor, $applicationId);
			$before = $this->deployment($actor, $applicationId, $identifier);
			$query = $this->database->queryCollection("admin_deployment");

			if($query->fetchInt("countDependents", $identifier, $identifier) > 0) {
				throw new InvalidArgumentException("Deployments with users or emails cannot be deleted.");
			}

			if($application->productionApplicationDeploymentId === $identifier) {
				$this->database->queryCollection("admin_application")->update("update",
					$application->name, null, $applicationId);
				$this->audit->recordChange(
					Action::APPLICATION_UPDATED,
					before: (object)["productionApplicationDeploymentId" => $identifier],
					after: (object)["productionApplicationDeploymentId" => null],
					actor: $actor,
					context: ["applicationId" => $applicationId],
				);
			}

			if($query->delete("delete", $identifier, $applicationId) !== 1) {
				throw new InvalidArgumentException("Choose a deployment assigned to this application.");
			}

			$this->audit->recordChange(
				Action::DEPLOYMENT_DELETED,
				before: $this->deploymentDetails($before),
				after: null,
				actor: $actor,
				context: ["applicationId" => $applicationId],
			);
			return $before->providerHost;
		});
		$this->removeDnsZone($hostname);
	}

	private function removeDnsZone(string $host):void {
		if($this->nsd === null) {
			return;
		}
		$hostname = (new Hostname())->publicName($host);
		foreach($this->database->queryCollection("admin_deployment")->fetchAll("dnsHosts") as $row) {
			if((new Hostname())->publicName($row->getString("providerHost")) === $hostname) {
				return;
			}
		}
		$this->nsd->remove($host);
	}

	private function deploymentDetails(ApplicationDeployment $deployment):object {
		return (object)[
			"id" => $deployment->id,
			"title" => $deployment->title,
			"providerHost" => $deployment->providerHost,
			"clientHost" => $deployment->clientHost,
			"clientLoginPath" => $deployment->clientLoginPath,
			"emailPrefix" => $deployment->emailPrefix,
			"emailReplyTo" => $deployment->emailReplyTo,
			"domainVerified" => $deployment->domainVerified,
			"clientConnected" => $deployment->clientConnected,
			"userLoggedIn" => $deployment->userLoggedIn,
		];
	}

	private function deployment(User $actor, string $applicationId,
		string $identifier):ApplicationDeployment {
		foreach($this->deployments($actor, $applicationId) as $deployment) {
			if($deployment->id === $identifier) {
				return $deployment;
			}
		}

		throw new InvalidArgumentException("Choose a deployment assigned to this application.");
	}

	public function hasConnectedApplication(User $administrator):bool {
		return $this->database->queryCollection("admin_deployment")
			->fetchInt("hasConnectedApplication", $administrator->id) > 0;
	}

	public function hasClientLogin(User $administrator):bool {
		return $this->database->queryCollection("admin_deployment")
			->fetchInt("hasClientLogin", $administrator->id) > 0;
	}

	public function recordClientConnection(string $deploymentId, Uri $uri, AnonUser $actor):void {
		if(!in_array($uri->getPath(), ["", "/"], true)) {
			return;
		}

		$this->transaction(function()use($deploymentId, $uri, $actor):void {
			$deployment = $this->productionDeployment($deploymentId);
			$host = $uri->getHost() . ($uri->getPort() ? ":" . $uri->getPort() : "");

			if(!$deployment || strcasecmp($host, $deployment->providerHost) !== 0) {
				return;
			}

			$changed = $this->database->queryCollection("admin_deployment")
				->update("connectClient", $deploymentId, $deployment->providerHost);

			if($changed === 1) {
				$this->audit->recordChange(Action::CLIENT_CONNECTED,
					(object)["clientConnected" => false], (object)["clientConnected" => true],
					$actor, ["deploymentId" => $deploymentId]);
			}
		});
	}

	public function recordClientLogin(User $actor):void {
		$this->transaction(function()use($actor):void {
			$deployment = $this->productionDeployment($actor->applicationDeploymentId);

			if(!$deployment) {
				return;
			}

			$changed = $this->database->queryCollection("admin_deployment")
				->update("recordUserLogin", $deployment->id, $deployment->providerHost);

			if($changed === 1) {
				$this->audit->recordChange(Action::FIRST_CLIENT_LOGIN,
					(object)["userLoggedIn" => false], (object)["userLoggedIn" => true],
					$actor, ["deploymentId" => $deployment->id]);
			}
		});
	}

	private function productionDeployment(string $id):?ApplicationDeployment {
		$deployment = $this->getDeploymentById($id);

		if(!$deployment || !$deployment->domainVerified
		|| $this->domains->isLocal($deployment->providerHost)
		|| $deployment->application->productionApplicationDeploymentId !== $id) {
			return null;
		}

		return $deployment;
	}

	public function hasVerifiedDomain(User $administrator):bool {
		$rows = $this->database->queryCollection("admin_deployment")
			->fetchAll("getDomainsByAdministrator", $administrator->id);

		foreach($rows as $row) {
			if($row->getBool("domainVerified")
				&& !$this->domains->isLocal($row->getString("providerHost"))) {
				return true;
			}
		}

		return false;
	}

	public function verifyDeployment(User $actor, string $applicationId, string $id):bool {
		$deployment = $this->deployment($actor, $applicationId, $id);

		if($this->domains->isLocal($deployment->providerHost)) {
			return false;
		}

		if($deployment->domainVerified) {
			return true;
		}

		$verified = $this->domains->verify($deployment->providerHost);

		return $this->transaction(function()use(
			$actor, $applicationId, $id, $deployment, $verified,
		):bool {
			$current = $this->deployment($actor, $applicationId, $id);
			$sameHost = $this->domains->hostname($current->providerHost)
				=== $this->domains->hostname($deployment->providerHost);

			if(!$verified || !$sameHost) {
				$this->audit->create(Action::DOMAIN_VERIFICATION_CHECKED,
					["deploymentId" => $id, "result" => $sameHost ? "pending" : "changed"], $actor);
				return false;
			}

			if($current->domainVerified) {
				return true;
			}

			$updated = $this->database->queryCollection("admin_deployment")
				->update("verifyDomain", $id, $applicationId, $current->providerHost);

			if($updated !== 1) {
				$this->audit->create(Action::DOMAIN_VERIFICATION_CHECKED,
					["deploymentId" => $id, "result" => "changed"], $actor);
				return false;
			}

			$this->audit->recordChange(Action::DOMAIN_VERIFIED,
				before: (object)["domainVerified" => false],
				after: (object)["domainVerified" => true],
				actor: $actor, context: ["deploymentId" => $id, "providerHost" => $current->providerHost]);
			return true;
		});
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
