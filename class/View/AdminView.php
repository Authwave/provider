<?php
namespace Authwave\View;

use Authwave\Admin\DemoWorkspace;
use Authwave\UI\EmailAvatar;
use DateTimeImmutable;
use Gt\Dom\{Element, HTMLDocument};
use Gt\DomTemplate\Binder;
use Gt\Http\Uri;
use Gt\Input\Input;

/** Shared binding and HTTP form helpers; view logic lives beside its HTML. */
class AdminView {
	public bool $reveal;
	private array $state = [];
	public string $page;
	public function __construct(
		public HTMLDocument|Element $document,
		public Binder $binder,
		public Input $input,
		public DemoWorkspace $workspace,
		Uri $uri,
	) {
		$this->reveal = $input->getString("reveal") === "yes";
		$this->page = basename(rtrim($uri->getPath(), "/"));
		if($this->page === "admin") $this->page = "index";
	}

	public function keys(array $values):void {
		$this->state = $values + $this->state;
		foreach($values as $key => $value) $this->binder->bindKeyValue($key, $value);
	}

	public function list(string $name, array $rows):void {
		$this->binder->bindList($rows, templateName: $name);
	}

	public function userRow(array $user):array {
		return ["userEmail" => DemoWorkspace::email($user["email"], $this->reveal), "userAvatar" => EmailAvatar::svg($user["email"]), "userApplication" => $user["application"], "userLogins" => $user["logins"], "userCreated" => $user["created"], "userLastSeen" => $user["lastSeen"], "userCountry" => $user["country"], "userDevice" => $user["device"]];
	}

	public function signupRow(array $user, string $timeKey = "createdAt"):array {
		$date = new DateTimeImmutable($user[$timeKey] ?? $user["created"]);
		return $this->userRow($user) + [
			"signupTime" => $date->format(DATE_ATOM),
			"signupTimeTitle" => $date->format("j F Y, H:i T"),
			"signupTimeText" => \Authwave\Admin\FriendlyTime::format($date),
		];
	}

	public function choice(string $name, array $choices, string $default):string {
		$value = $this->input->getString($name);
		return in_array($value, $choices, true) ? $value : $default;
	}

	public function date(string $key):?DateTimeImmutable {
		$value = $this->input->getString($key) ?? "";
		$date = DateTimeImmutable::createFromFormat("!Y-m-d", $value);
		return $date && $date->format("Y-m-d") === $value ? $date : null;
	}

	public function bindSettings(array $settings):void {
		foreach($this->owned("[data-setting]") as $field) {
			$key = $field->getAttribute("data-setting");
			if(!array_key_exists($key, $settings)) continue;
			$value = $key === "smtpPassword" ? "" : $settings[$key];
			if($field->tagName === "textarea") $field->textContent = $value;
			elseif($field->tagName === "select") foreach($field->querySelectorAll("option") as $option) $option->toggleAttribute("selected", $option->value === $value);
			elseif($field->getAttribute("type") === "checkbox") $field->toggleAttribute("checked", $value === "yes");
			else $field->setAttribute("value", $value);
		}
	}

	public function forms():void {
		foreach($this->owned("select[data-filter]") as $select) {
			$name = $select->getAttribute("name");
			// Prefer values already normalised and bound by the page model.
			$value = $this->state[$name] ?? $this->input->getString($name);
			$defaults = ["comparison" => "previous", "userSort" => "logins", "sender" => "accounts", "template" => "security-code", "deliveryTemplate" => "all", "deliveryStatus" => "all", "userStatus" => "all"];
			if(!$select->querySelector('option[value="' . preg_replace('/[^a-zA-Z0-9-]/', '', $value ?? '') . '"]')) $value = $defaults[$name] ?? "all";
			foreach($select->querySelectorAll("option") as $option) $option->toggleAttribute("selected", $option->value === $value);
		}
		foreach($this->owned("form") as $form) {
			if($form->hasAttribute("data-scope-form")) {
				$form->setAttribute("action", "/admin/" . ($this->page === "index" ? "" : "$this->page/"));
				continue;
			}
			foreach($this->workspace->scope() as $key => $value) if(!$form->querySelector('[name="' . $key . '"]')) $this->hidden($form, $key, $value);
			if($form->getAttribute("method") === "post") {
				$form->setAttribute("action", $this->workspace->url($this->page === "index" ? "" : $this->page));
				if($form->querySelector('[value="template"]')) $this->hidden($form, "template", $this->choice("template", ["verify", "welcome", "security-code", "password-changed"], "security-code"));
				if($form->querySelector('[value="sender"]')) $this->hidden($form, "sender", $this->choice("sender", array_merge(["new"], array_keys($this->workspace->senders())), "accounts"));
			}
			else {
				foreach(["activitySuccess", "activityFailed", "activityAbandoned", "reportComparison", "period", "status", "method", "activity", "sort", "from", "to", "comparison", "topUsage", "reveal"] as $key) {
					if($value = $this->state[$key] ?? $this->input->getString($key)) if(!$form->querySelector('[name="' . $key . '"]')) $this->hidden($form, $key, substr((string)$value, 0, 100));
				}
			}
		}
		foreach($this->owned('a[href^="/admin/"]') as $link) {
			if($link->hasAttribute("data-nav")) continue;
			$href = $link->getAttribute("href");
			if(str_contains($href, "organisation=")) continue;
			[$base, $fragment] = array_pad(explode("#", $href, 2), 2, "");
			$link->setAttribute("href", $base . (str_contains($base, "?") ? "&" : "?") . http_build_query($this->workspace->scope()) . ($fragment ? "#$fragment" : ""));
		}
	}

	/** A parent page/component must leave nested components' controls to their own logic. */
	private function owned(string $selector):iterable {
		foreach($this->document->querySelectorAll($selector) as $element) {
			$owned = true;
			for($parent = $element->parentElement; $parent; $parent = $parent->parentElement) {
				if($parent === $this->document) break;
				if(str_contains($parent->tagName, "-")) {
					$owned = false;
					break;
				}
			}
			if($owned) yield $element;
		}
	}

	public function hidden(Element $form, string $name, string $value):void {
		if($form->querySelector('[name="' . $name . '"]')) return;
		$input = $form->ownerDocument->createElement("input");
		$input->setAttribute("type", "hidden");
		$input->setAttribute("name", $name);
		$input->setAttribute("value", $value);
		$form->appendChild($input);
	}

}
