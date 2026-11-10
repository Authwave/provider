import {updateCsrfTokens} from "../app/form-submission.es6";

class SudoFields {
	constructor() {
		this.timers = new WeakMap();
		this.initialise();
		document.addEventListener("flux:before-render", event => {
			const incoming = event.detail?.updates?.find(update => update.newElement)?.newElement.ownerDocument;
			const until = incoming?.querySelector("[data-sudo-disable]")?.dataset.sudoUntil;

			if(until !== undefined) {
				this.extend(Number(until));
			}
		});
		document.addEventListener("flux:after-render", () => this.initialise());
		document.addEventListener("submit", event => this.submit(event), {capture: true});
		for(const eventName of ["pointerdown", "pointermove", "keydown", "wheel"]) {
			document.addEventListener(eventName, event => {
				if(event.isTrusted && !this.activityTimer
				&& document.querySelector("[data-sudo-disable]:not([hidden])")) {
					this.activityTimer = setTimeout(() => {
						this.activityTimer = null;
						this.renewActivity();
					}, 1000);
				}
			}, {passive: true});
		}
		window.addEventListener("pagehide", () => {
			clearTimeout(this.activityTimer);
			this.activityTimer = null;
			this.activityRequest?.abort();
			this.obscureAll();
		});
		window.addEventListener("pageshow", event => {
			if(event.persisted) {
				this.initialise();
			}
		});
	}

	initialise() {
		const url = new URL(location.href);
		const requested = url.searchParams.get("sudo-view");
		if(requested !== null) {
			url.searchParams.delete("sudo-view");
			history.replaceState(history.state, "", url);
		}

		for(const field of document.querySelectorAll('[data-sudo][data-copy], [data-sudo="email"]')) {
			const group = field.closest(".sudo-control");
			if(!group || group.querySelector("[data-sudo-copy]")) {
				continue;
			}

			const view = group.querySelector("[data-sudo-view]");
			const copy = view.cloneNode(true);
			copy.removeAttribute("data-sudo-view");
			copy.removeAttribute("aria-pressed");
			copy.dataset.sudoCopy = field.id;
			copy.dataset.icon = "copy";
			copy.value = "copy";
			copy.hidden = field.dataset.sudo === "email";
			copy.querySelector("span").textContent = "Copy";
			copy.setAttribute("aria-label", view.getAttribute("aria-label").replace(/^View /, "Copy "));
			group.append(copy);
		}

		for(const field of document.querySelectorAll("[data-sudo-enabled]")) {
			if((field.dataset.sudo === "email" || field.id === requested)
			&& Number(field.dataset.sudoUntil) * 1000 > Date.now()) {
				this.show(field, field.value ?? field.textContent, field.dataset.sudoUntil);
			}
			else {
				this.obscure(field);
			}
		}

		if(requested !== null) {
			document.getElementById(requested)?.closest("[popover]")?.showPopover();
		}

		for(const button of document.querySelectorAll("[data-sudo-required]")) {
			clearTimeout(this.timers.get(button));
			const authorise = button.form.querySelector("[data-sudo-authorise]");
			const remaining = Number(button.dataset.sudoUntil) * 1000 - Date.now();
			button.disabled = remaining <= 0;
			authorise.hidden = remaining > 0;

			if(remaining > 0) {
				this.expire(button, () => {
					button.disabled = true;
					authorise.hidden = false;
				});
			}
		}

		for(const indicator of document.querySelectorAll("[data-sudo-disable]")) {
			clearTimeout(this.timers.get(indicator));
			const remaining = Number(indicator.dataset.sudoUntil) * 1000 - Date.now();
			if(remaining <= 0) {
				indicator.hidden = true;
				continue;
			}
			indicator.hidden = false;
			this.expire(indicator, () => {
				indicator.hidden = true;
				this.obscureAll();
			});
		}
	}

	expire(element, callback) {
		clearTimeout(this.timers.get(element));
		const remaining = Number(element.dataset.sudoUntil) * 1000 - Date.now();

		if(remaining <= 0) {
			callback();
			return;
		}

		this.timers.set(element, setTimeout(() => this.expire(element, callback), remaining));
	}

	extend(until) {
		if(!Number.isFinite(until)) {
			return;
		}

		for(const element of document.querySelectorAll("[data-sudo-until]")) {
			element.dataset.sudoUntil = until === 0 ? 0 : Math.max(until, Number(element.dataset.sudoUntil));
		}

		if(until === 0) {
			this.initialise();
		}
	}

	async renewActivity() {
		const indicator = document.querySelector("[data-sudo-disable]:not([hidden])");

		if(!indicator || this.renewing || document.visibilityState !== "visible"
		|| Number(indicator.dataset.sudoUntil) * 1000 <= Date.now()) {
			return;
		}

		this.renewing = true;
		this.activityRequest = new AbortController();
		try {
			const response = await fetch("/sudo/?activity=1", {
				credentials: "same-origin", cache: "no-store", signal: this.activityRequest.signal,
			});

			if(response.ok) {
				this.extend(Number(response.headers.get("X-Authwave-Sudo-Until")));
				const page = new DOMParser().parseFromString(await response.text(), "text/html");
				updateCsrfTokens(page);
			}
		}
		catch {
			// A failed request cannot extend server authorisation.
		}
		finally {
			this.renewing = false;
			this.activityRequest = null;
		}
	}

	obscure(field) {
		clearTimeout(this.timers.get(field));
		const mask = field.dataset.sudoEnabled;
		if(mask === undefined) {
			return;
		}

		this.setValue(field, mask);
		const group = field.closest(".sudo-control");
		const view = group.querySelector("[data-sudo-view]");
		view.setAttribute("aria-pressed", "false");

		if(field.dataset.sudo === "email") {
			view.hidden = false;
			group.querySelector("[data-sudo-copy]").hidden = true;
		}
	}

	obscureAll() {
		for(const field of document.querySelectorAll("[data-sudo-enabled]")) {
			this.obscure(field);
		}
	}

	setValue(field, value) {
		if("value" in field && field.tagName !== "OUTPUT") {
			field.value = value;
			field.defaultValue = value;
		}
		else {
			field.textContent = value;
		}
	}

	show(field, value, until) {
		this.setValue(field, value);
		const group = field.closest(".sudo-control");
		const view = group.querySelector("[data-sudo-view]");
		view.setAttribute("aria-pressed", "true");

		if(field.dataset.sudo === "email") {
			view.hidden = true;
			group.querySelector("[data-sudo-copy]").hidden = false;
		}

		clearTimeout(this.timers.get(field));
		field.dataset.sudoUntil = until;
		this.expire(field, () => this.obscure(field));
	}

	async copy(value) {
		if(navigator.clipboard) {
			await navigator.clipboard.writeText(value);
			return;
		}

		// HTTP development origins do not expose the Clipboard API.
		const field = document.createElement("textarea");
		field.value = value;
		field.readOnly = true;
		field.style.position = "fixed";
		field.style.opacity = "0";
		field.setAttribute("aria-hidden", "true");
		const focused = document.activeElement;
		document.body.append(field);
		field.select();

		try {
			if(!document.execCommand("copy")) {
				throw new Error("Unable to copy to the clipboard.");
			}
		}
		finally {
			field.remove();
			focused?.focus();
		}
	}

	async submit(event) {
		const button = event.submitter;
		if(!button?.matches("[data-sudo-view], [data-sudo-copy]")) {
			// Read-only credentials should not be submitted with unrelated changes.
			for(const field of document.querySelectorAll('[data-sudo-enabled]:not([data-sudo="email"])')) {
				this.obscure(field);
			}
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();
		const field = document.getElementById(button.dataset.sudoView || button.dataset.sudoCopy);
		if(this.pending) {
			return;
		}

		if(button.dataset.sudoView && button.getAttribute("aria-pressed") === "true") {
			if(field.dataset.sudo !== "email") {
				this.obscure(field);
			}
			return;
		}

		this.pending = true;
		button.disabled = true;
		field.closest(".sudo-control").querySelector("[role=status]")?.remove();
		try {
			const data = new FormData(event.target);
			data.set("do", button.value);
			const response = await fetch(button.formAction, {method: "POST", body: data, cache: "no-store"});
			if(new URL(response.url).pathname === "/login/sudo/") {
				location.assign(response.url);
				return;
			}

			if(!response.ok) {
				throw new Error("Unable to access this protected field.");
			}

			this.extend(Number(response.headers.get("X-Authwave-Sudo-Until")));
			const page = new DOMParser().parseFromString(await response.text(), "text/html");
			updateCsrfTokens(page);

			const authorised = page.getElementById(field.id);
			if(!authorised?.hasAttribute("data-sudo-enabled")
				|| Number(authorised.dataset.sudoUntil) * 1000 <= Date.now()) {
				throw new Error("Sudo mode has expired. Please try again.");
			}

			const value = authorised.value ?? authorised.textContent;
			field.dataset.sudoEnabled = authorised.dataset.sudoEnabled;
			if(button.dataset.sudoCopy) {
				await this.copy(value);
			}
			else {
				this.show(field, value, authorised.dataset.sudoUntil);
			}
		}
		catch(error) {
			const message = field.closest(".sudo-control").querySelector("[role=status]") || document.createElement("small");
			message.setAttribute("role", "status");
			message.textContent = error.message;
			field.closest(".sudo-control").append(message);
		}
		finally {
			this.pending = false;
			button.disabled = false;
		}
	}
}

new SudoFields();
