<?php
namespace Authwave\Security;

use Authwave\Model\User;
use Authwave\User\UserRepository;
use Closure;
use GT\Dom\Element;
use GT\Dom\HTMLDocument;
use GT\Http\Uri;
use GT\Session\SessionStoreInterface;
use InvalidArgumentException;
use LogicException;

readonly class Sudo {
	private Closure $clock;

	public function __construct(
		private SessionStoreInterface $session,
		private UserRepository $users,
		private Audit $audit,
		private int $duration = 60,
		?Closure $clock = null,
	) {
		if($duration < 1) {
			throw new InvalidArgumentException("Sudo duration must be positive.");
		}

		$this->clock = $clock ?? fn():int => time();
	}

	public function expiresAt(User $actor):int {
		if($this->session->get("actor") !== $actor->id) {
			return 0;
		}

		$until = $this->session->getInt("until") ?? 0;

		if($until > 0 && $until <= ($this->clock)()) {
			$this->clear();
			$this->audit->create(Action::SUDO_EXPIRED, null, $actor);
			return 0;
		}

		return $until;
	}

	public function renew(User $actor):int {
		if($this->expiresAt($actor) === 0) {
			return 0;
		}

		$until = ($this->clock)() + $this->duration;
		$this->session->set("until", $until);
		return $until;
	}

	public function request(User $actor, string $field, string $return, string $action):Uri {
		$return = (string)$this->returnUri($return)->withoutQueryValue("sudo-view");

		if(!preg_match('/^[a-zA-Z0-9_-]{1,200}$/', $field)
		|| !in_array($action, ["view", "copy", "delete"], true)) {
			throw new InvalidArgumentException("Invalid sudo field or action.");
		}

		$active = $this->expiresAt($actor) > 0;

		if(!$active) {
			$this->audit->create(Action::SUDO_REQUESTED,
				["field" => $field, "action" => $action], $actor);
			$this->session->remove("until");
		}

		$this->session->set("actor", $actor->id);
		$this->session->set("field", $field);
		$this->session->set("return", $return);
		$this->session->set("action", $action);
		$this->session->set("reveal", $active);

		return new Uri($active ? $return : "/login/sudo/");
	}

	public function pending(User $actor):bool {
		return $this->session->get("actor") === $actor->id
			&& $this->session->getString("return") !== null;
	}

	public function authenticate(User $actor, string $password):bool {
		if(!$this->pending($actor)) {
			throw new LogicException("No sudo authorisation has been requested.");
		}

		if(!$this->users->checkLogin($actor, $password)) {
			$this->audit->create(Action::SUDO_REJECTED, null, $actor);
			return false;
		}

		$until = ($this->clock)() + $this->duration;
		$this->audit->create(Action::SUDO_AUTHORISED, ["until" => $until], $actor);
		$this->session->set("until", $until);
		$this->session->set("reveal", true);
		return true;
	}

	public function destination(User $actor):Uri {
		if(!$this->pending($actor)) {
			return new Uri("/admin/");
		}

		$uri = $this->returnUri($this->session->getString("return"));

		if($this->session->get("action") === "view"
		&& $this->session->get("reveal") && $this->expiresAt($actor) > 0) {
			$uri = $uri->withQueryValue("sudo-view", $this->session->getString("field"));
		}

		if($this->session->get("action") === "delete") {
			foreach(["field", "return", "action", "reveal"] as $key) {
				$this->session->remove($key);
			}
		}

		return $uri;
	}

	public function reveal(User $actor, string $field, string $location,
		string $rule = "key"):bool {
		if($this->expiresAt($actor) === 0) {
			return false;
		}

		$requested = $this->session->get("reveal")
			&& $this->session->get("field") === $field
			&& $this->session->get("return") === $location;

		if($rule === "key" && !$requested) {
			return false;
		}

		$action = $requested ? $this->session->getString("action") : "view";
		$this->audit->create(Action::SUDO_FIELD_ACCESSED,
			["field" => $field, "action" => $action], $actor);

		if($requested) {
			$this->session->remove("reveal");
			$this->session->remove("field");
			$this->session->remove("return");
			$this->session->remove("action");
		}

		return true;
	}

	public function disable(User $actor):void {
		$this->clear();
		$this->audit->create(Action::SUDO_DISABLED, null, $actor);
	}

	public function obscure(string $value, string $rule = ""):string {
		$prefix = "";

		if($rule === "email") {
			$parts = explode("@", $value);

			if(count($parts) === 2 && $parts[0] !== "" && $parts[1] !== "") {
				[$local, $domain] = $parts;
				return mb_substr($local, 0, 2) . str_repeat("•", max(0, mb_strlen($local) - 2))
					. "@" . mb_substr($domain, 0, 2) . str_repeat("•", max(0, mb_strlen($domain) - 2));
			}
		}

		if($rule === "key" && preg_match('/^[^_]+_[^_]+_/', $value, $match)) {
			// Always leave some of the secret hidden, including unexpectedly short keys.
			$length = strlen($match[0]);
			$visible = min(4, max(0, strlen($value) - $length - 1));
			$prefix = substr($value, 0, $length + $visible);
		}

		return $prefix . str_repeat("•", mb_strlen(substr($value, strlen($prefix))));
	}

	public function returnUri(string $return):Uri {
		$path = parse_url($return, PHP_URL_PATH);
		$decoded = rawurldecode(is_string($path) ? $path : "");
		$admin = str_starts_with($return, "/admin/");
		$help = str_starts_with($return, "/integrations/help/") && $path === "/integrations/help/";

		if((!$admin && !$help)
		|| preg_match('/[\x00-\x20\x7f\\\\]/', $return)
		|| preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)
		|| preg_match('~/(?:\.|\.\.)(?:/|$|[?#])~', $decoded)
		|| str_contains($decoded, "%") || str_contains($decoded, "//")) {
			throw new InvalidArgumentException("Sudo must return to an administration page.");
		}

		return new Uri($return);
	}

	public function apply(HTMLDocument $document, User $actor, Uri $uri):void {
		$uri = $uri->withoutQueryValue("sudo-view");
		$location = $uri->getPath();

		if($uri->getQuery() !== "") {
			$location .= "?" . $uri->getQuery();
		}

		foreach($document->querySelectorAll("[data-sudo]") as $index => $field) {
			$value = $field->hasAttribute("value") ? $field->getAttribute("value") : $field->textContent;
			$rule = $field->getAttribute("data-sudo") ?? "";
			$obscured = $this->obscure($value, $rule);
			$panel = $field->closest("[id]");
			$id = $field->id ?: ($panel->id ?? "sudo") . "-" . ($field->getAttribute("name") ?? $index);
			$field->id = $id;
			$revealed = $this->reveal($actor, $id, $location, $rule);

			if($revealed && $value !== "") {
				$field->setAttribute("data-sudo-enabled", $obscured);
				$field->setAttribute("data-sudo-until", (string)$this->expiresAt($actor));
				$field->closest("details")?->setAttribute("open", "");
			}
			elseif($field->hasAttribute("value")) {
				$field->setAttribute("value", $obscured);
			}
			else {
				$field->textContent = $obscured;
			}

			$this->addFieldControls($field, $location, $value === "");
		}
	}

	private function addFieldControls(Element $field, string $location, bool $empty):void {
		$document = $field->ownerDocument;
		$label = $field->closest("label");
		$group = $document->createElement($field->hasAttribute("value") ? "div" : "span");
		$group->className = "sudo-control";
		$field->before($group);
		$group->appendChild($field);

		if($label) {
			// Keep buttons outside the input's label: they have their own accessible names.
			$container = $document->createElement("div");
			$container->className = trim("field " . $label->className);
			$label->before($container);
			$label->setAttribute("for", $field->id);
			$container->appendChild($label);
			$container->appendChild($group);
		}

		$url = "/sudo/?" . http_build_query(["field" => $field->id, "return" => $location]);
		$button = $document->createElement("button");
		$button->setAttribute("type", "submit");
		$button->setAttribute("form", "sudo-controls");
		$button->setAttribute("formaction", $url);
		$button->setAttribute("formnovalidate", "");
		$button->setAttribute("name", "do");
		$button->setAttribute("value", "view");
		$button->setAttribute("data-sudo-view", $field->id);
		$button->setAttribute("data-icon", "eye");
		$caption = trim($label->textContent ?? "protected field");

		if(!$label && $field->getAttribute("data-sudo") === "email") {
			$caption = "email address";
		}

		$button->setAttribute("aria-label", "View " . $caption);
		$button->className = "icon-button";
		$text = $document->createElement("span");
		$text->textContent = "View";
		$button->appendChild($text);

		if($empty) {
			$button->setAttribute("disabled", "");
		}

		$group->appendChild($button);
	}

	private function clear():void {
		foreach(["actor", "until", "field", "return", "action", "reveal"] as $key) {
			$this->session->remove($key);
		}
	}
}
