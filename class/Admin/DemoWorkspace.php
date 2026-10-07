<?php
namespace Authwave\Admin;

use Authwave\Session\LoginSession;
use Gt\Input\Input;
use Gt\Session\Session;
use Gt\Session\SessionStore;
use InvalidArgumentException;

/** Session-local preview data. Never changes provider records or sends messages. */
class DemoWorkspace {
	private SessionStore $store;
	public string $organisationId;
	public string $applicationId;
	public string $deploymentId;
	public array $data;
	public ?string $changedSender = null;

	public function __construct(Session $session, LoginSession $login, Input $input) {
		$this->store = $session->getStore("AUTHWAVE_ADMIN_DEMO", true);
		$this->data = $this->store->get("data") ?? $this->seed($login);
		$organisation = $input->getString("organisation") ?? $this->store->getString("organisation") ?? "example";
		$this->organisationId = isset($this->data["organisations"][$organisation]) ? $organisation : "example";
		$application = $input->getString("application") ?? $this->store->getString("application") ?? "all";
		$this->applicationId = isset($this->organisation()["applications"][$application]) ? $application : (array_key_first($this->organisation()["applications"]) ?? "");
		$deployment = $input->getString("deploymentScope") ?? (($input->getString("application") || $input->getString("organisation")) ? "all" : ($this->store->getString("deployment") ?? "all"));
		if($selection = $input->getString("scope")) {
			$values = json_decode($selection, true);
			if(is_array($values) && count($values) === 3 && array_is_list($values)
			&& is_string($values[0]) && is_string($values[1]) && is_string($values[2])
			&& isset($this->data["organisations"][$values[0]])
			&& isset($this->data["organisations"][$values[0]]["applications"][$values[1]])) {
				[$this->organisationId, $this->applicationId, $deployment] = $values;
			}
		}
		$this->deploymentId = $this->applicationId !== "" && isset($this->organisation()["applications"][$this->applicationId]["deployments"][$deployment]) ? $deployment : "all";
		$this->persist();
	}

	public static function selectionValue(string $organisation, string $application, string $deployment = "all"):string {
		return json_encode([$organisation, $application, $deployment], JSON_THROW_ON_ERROR);
	}

	public function organisation():array { return $this->data["organisations"][$this->organisationId]; }
	public function applications():array {
		$apps = $this->organisation()["applications"];
		if($this->applicationId === "") return [];
		$app = $apps[$this->applicationId];
		if($this->deploymentId !== "all") $app["deployments"] = [$this->deploymentId => $app["deployments"][$this->deploymentId]];
		return [$this->applicationId => $app];
	}
	public function scope():array { return ["organisation" => $this->organisationId, "application" => $this->applicationId, "deploymentScope" => $this->deploymentId]; }
	public function url(string $page = "", array $query = []):string {
		return "/admin/" . ($page ? "$page/" : "") . "?" . http_build_query($query + $this->scope());
	}
	public function settings():array {
		return ($this->organisation()["settings"][$this->applicationId] ?? []) + ($this->organisation()["settings"]["all"] ?? []) + $this->defaults();
	}
	public function senders():array {
		return ["accounts" => "Account emails", "support" => "Support team"] + ($this->organisation()["senders"] ?? []);
	}
	public function setNotice(string $message):void { $this->store->set("notice", $message); }
	public function notice():string {
		$message = $this->store->getString("notice") ?? "";
		$this->store->remove("notice");
		return $message;
	}

	public function users():array {
		$users = [];
		foreach($this->applications() as $id => $app) {
			foreach([["Sienna Hewitt", "sienna", "United Kingdom", "Desktop"], ["Ammar Foley", "ammar", "Germany", "Mobile"], ["Pippa Wilkinson", "pippa", "United States", "Mobile"], ["Olly Schroeder", "olly", "France", "Desktop"], ["Mathilde Lewis", "mathilde", "Canada", "Tablet"], ["Julius Vaughan", "julius", "Netherlands", "Desktop"], ["Zaid Schwartz", "zaid", "United Kingdom", "Mobile"]] as $i => [$name, $local, $country, $device]) {
				$createdAt = new \DateTimeImmutable($i === 0 ? "-2 hours" : "-$i days 09:00");
				$users[] = ["id" => "$id-$i", "name" => $name, "email" => "$local@example.test", "application" => $app["name"], "applicationId" => $id, "country" => $country, "device" => $device, "logins" => 143 - $i * 17, "created" => $createdAt->format("Y-m-d"), "createdAt" => $createdAt->format(DATE_ATOM), "signupStartedAt" => date(DATE_ATOM, strtotime("-3 hours")), "lastSeen" => $i < 3 ? "Just now" : "$i hours ago", "online" => $i < 3, "status" => $i === 3 ? "Failed" : ($i === 6 ? "Abandoned" : "Success")];
			}
		}
		return array_merge($users, array_values(array_filter($this->organisation()["users"] ?? [], fn($user) => $user["applicationId"] === $this->applicationId)));
	}
	public static function email(string $email, bool $reveal = false):string {
		if($reveal) return $email;
		[$local, $domain] = explode("@", $email, 2);
		return substr($local, 0, 1) . "••••@" . $domain;
	}

	public function mutate(Input $input):void {
		$operation = $input->getString("operation") ?? "";
		$org =& $this->data["organisations"][$this->organisationId];
		$fields = [
			"password" => ["passwordMin", "passwordUpper", "passwordNumber", "passwordSymbol"],
			"methods" => ["password", "email", "totp", "securityKey", "google", "facebook", "twitter"],
			"sessions" => ["sessionTimeout", "idleTimeout"],
			"sender" => ["senderName", "senderEmail", "replyTo", "senderDomain"],
			"smtp" => ["smtpHost", "smtpPort", "smtpUsername", "smtpPassword"],
			"template" => ["templateSubject", "templateContent"],
			"customisation" => ["logoUrl", "darkLogoUrl", "primaryColour", "secondaryColour", "cssOverrides"],
			"plan" => ["plan"],
		];
		if(isset($fields[$operation])) {
			$key = $this->applicationId;
			$values = [];
			if($operation === "sender") {
				$sender = $input->getString("sender") ?? "accounts";
				if($sender !== "new" && !isset($this->senders()[$sender])) throw new InvalidArgumentException("Unknown sender.");
				if($sender === "new") $sender = "sender-" . bin2hex(random_bytes(4));
				$key .= ":sender:$sender";
			}
			if($operation === "template") {
				$template = $input->getString("template") ?? "verify";
				if(!in_array($template, ["verify", "welcome", "security-code", "password-changed"], true)) throw new InvalidArgumentException("Unknown template.");
				$key .= ":template:$template";
			}
			foreach($fields[$operation] as $field) {
				$value = substr(trim($input->getString($field) ?? ""), 0, $field === "templateContent" || $field === "cssOverrides" ? 10000 : 500);
				if(in_array($field, ["passwordMin", "sessionTimeout", "idleTimeout", "smtpPort"], true) && (!ctype_digit($value) || (int)$value < 1 || (int)$value > 65535)) throw new InvalidArgumentException("Enter a positive number for $field.");
				if($field === "passwordMin" && ((int)$value < 8 || (int)$value > 128)) throw new InvalidArgumentException("Password length must be between 8 and 128.");
				if(in_array($field, ["senderEmail", "replyTo"], true) && !filter_var($value, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException("Enter a valid email address.");
				if(in_array($field, ["primaryColour", "secondaryColour"], true) && !preg_match('/^#[0-9a-f]{6}$/i', $value)) throw new InvalidArgumentException("Enter a six-digit hex colour.");
				if($field === "plan" && !in_array($value, ["Starter", "Growth", "Scale"], true)) throw new InvalidArgumentException("Choose an available plan.");
				if($field === "smtpPassword" && $value === "") continue;
				$values[$field] = $value;
			}
			$org["settings"][$key] = $values + ($org["settings"][$key] ?? []);
			if($operation === "sender") {
				$this->changedSender = $sender;
				$org["senders"][$sender] = $org["settings"][$key]["senderName"];
			}
			$this->store->set("notice", "Demo settings saved.");
		}
		elseif($operation === "create-organisation") {
			$name = $this->required($input, "name");
			$id = "organisation-" . bin2hex(random_bytes(4));
			$this->data["organisations"][$id] = ["name" => $name, "applications" => [], "settings" => [], "steps" => []];
			$this->organisationId = $id;
			$this->applicationId = "";
			$this->deploymentId = "all";
			$this->store->set("notice", "Demo organisation created. Start by creating an application.");
		}
		elseif($operation === "create-application") {
			$name = $this->required($input, "name");
			$id = "application-" . bin2hex(random_bytes(4));
			$org["applications"][$id] = ["name" => $name, "production" => "", "deployments" => ["development" => $this->deployment("Development", "localhost", "http://localhost:8080", "/login/")]];
			$org["steps"]["application"] = true;
			$this->applicationId = $id;
			$this->deploymentId = "all";
			$this->store->set("notice", "Demo application created.");
		}
		elseif($operation === "application" || $operation === "deployment") {
			$id = $input->getString("entity");
			if(!isset($this->applications()[$id])) throw new InvalidArgumentException("Choose an application in this view.");
			if($operation === "application") {
				$production = $input->getString("production") ?? "";
				if($production !== "" && !isset($org["applications"][$id]["deployments"][$production])) throw new InvalidArgumentException("Unknown deployment.");
				$org["applications"][$id]["name"] = $this->required($input, "name");
				$org["applications"][$id]["production"] = $production;
			}
			else {
				$deployment = $input->getString("deployment") ?? "";
				if(!isset($org["applications"][$id]["deployments"][$deployment])) throw new InvalidArgumentException("Unknown deployment.");
				$values = [];
				foreach(["name", "host", "clientUrl", "loginPath"] as $field) $values[$field] = $this->required($input, $field);
				$org["applications"][$id]["deployments"][$deployment] = $values + $org["applications"][$id]["deployments"][$deployment];
			}
			$this->store->set("notice", "Demo application settings saved.");
		}
		elseif($operation === "setup") {
			$step = $input->getString("step");
			if(!in_array($step, ["domain", "deploy", "integration", "customisation", "production", "first-user"], true)) throw new InvalidArgumentException("Unknown setup step.");
			$org["steps"][$step] = true;
			$this->store->set("notice", "Demo setup step marked complete.");
		}
		elseif($operation === "create-user") {
			$app = $this->applicationId;
			if(!$app) throw new InvalidArgumentException("Create an application first.");
			$email = $this->required($input, "email");
			if(!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException("Enter a valid email address.");
			$org["users"][] = ["id" => bin2hex(random_bytes(4)), "name" => $this->required($input, "name"), "email" => $email, "application" => $org["applications"][$app]["name"], "applicationId" => $app, "country" => "United Kingdom", "device" => "Desktop", "logins" => 0, "created" => date("Y-m-d"), "createdAt" => date(DATE_ATOM), "lastSeen" => "Never", "online" => false, "status" => "New"];
			$this->store->set("notice", "Demo user added. No invitation was sent.");
		}
		elseif(in_array($operation, ["verify-domain", "test-email", "revoke-sessions", "revoke-device"], true)) {
			if($operation === "verify-domain") $org["settings"]["all"]["domainVerified"] = "yes";
			if($operation === "revoke-sessions") $org["settings"][$this->applicationId]["sessionsRevoked"] = "yes";
			if($operation === "revoke-device") {
				$device = $input->getString("entity");
				if(!in_array($device, ["desktop", "phone", "tablet"], true)) throw new InvalidArgumentException("Unknown device.");
				$org["revokedDevices"]["$this->applicationId:$device"] = true;
			}
			$this->store->set("notice", match($operation) {"test-email" => "Demo test email queued. No email was sent.", "verify-domain" => "Demo domain marked verified. No DNS check was performed.", default => "Demo sessions revoked. Real sessions were not changed."});
		}
		else throw new InvalidArgumentException("Unknown demo action.");
		$this->persist();
	}

	public function setupSteps():array {
		$steps = ["application" => ["Create your first application", "applications"], "domain" => ["Verify domain", "emails"], "deploy" => ["Deploy your application", "applications"], "integration" => ["Connect with a client integration", "integrations"], "customisation" => ["Customise your deployment", "customisation"], "production" => ["Enable production mode", "applications"], "first-user" => ["First user logs on", "users"]];
		$rows = [];
		foreach($steps as $id => [$title, $page]) {
			$complete = $id === "application" ? count($this->organisation()["applications"]) > 0 : ($this->organisation()["steps"][$id] ?? false);
			$rows[] = ["stepId" => $id, "stepTitle" => $title, "stepStatus" => $complete ? "Complete" : "To do", "stepComplete" => $complete, "stepIcon" => $complete ? "circle-check" : "shield", "isApplicationStep" => $id === "application", "stepUrl" => $this->url($page)];
		}
		return $rows;
	}

	private function required(Input $input, string $key):string {
		$value = substr(trim($input->getString($key) ?? ""), 0, 200);
		if($value === "") throw new InvalidArgumentException("Enter a value for $key.");
		return $value;
	}
	private function persist():void {
		$this->store->set("data", $this->data);
		$this->store->set("organisation", $this->organisationId);
		$this->store->set("application", $this->applicationId);
		$this->store->set("deployment", $this->deploymentId);
	}
	private function defaults():array {
		return ["passwordMin" => "12", "passwordUpper" => "yes", "passwordNumber" => "yes", "passwordSymbol" => "", "password" => "yes", "email" => "yes", "totp" => "yes", "securityKey" => "", "google" => "yes", "facebook" => "", "twitter" => "", "sessionTimeout" => "1440", "idleTimeout" => "30", "senderName" => "TrackShift", "senderEmail" => "accounts@example.test", "replyTo" => "support@example.test", "senderDomain" => "example.test", "domainVerified" => "", "smtpHost" => "smtp.example.test", "smtpPort" => "587", "smtpUsername" => "accounts", "smtpPassword" => "", "logoUrl" => "/asset/default-logo.svg", "darkLogoUrl" => "", "primaryColour" => "#c328d1", "secondaryColour" => "#9e77ed", "cssOverrides" => "", "plan" => "Growth", "sessionsRevoked" => ""];
	}
	private function deployment(string $name, string $host, string $clientUrl, string $loginPath):array {
		return ["name" => $name, "host" => $host, "clientUrl" => $clientUrl, "loginPath" => $loginPath, "apiKey" => "demo_key_" . strtolower($name) . "_not_a_real_secret"];
	}
	private function seed(LoginSession $login):array {
		$deployment = $login->getDeployment();
		$app = $deployment->application;
		return ["organisations" => [
			"example" => ["name" => "$app->name organisation", "applications" => [
				$app->id => ["name" => $app->name, "production" => "production", "deployments" => ["production" => $this->deployment($deployment->title, $deployment->providerHost ?: "login.example.test", (string)$deployment->getClientReturnUri()->withPath("/"), $deployment->clientLoginPath), "staging" => $this->deployment("Staging", "staging-login.example.test", "https://staging.example.test", "/login/")]],
				"operations" => ["name" => "Operations", "production" => "production", "deployments" => ["production" => $this->deployment("Operations", "operations-login.example.test", "https://operations.example.test", "/login/")]],
			], "settings" => [], "steps" => ["application" => true, "domain" => true, "deploy" => true]],
			"northstar" => ["name" => "Northstar Studio", "applications" => ["portal" => ["name" => "Customer portal", "production" => "production", "deployments" => ["production" => $this->deployment("Customer portal", "login.northstar.example.test", "https://northstar.example.test", "/sign-in/")]]], "settings" => [], "steps" => ["application" => true]],
		]];
	}
}
