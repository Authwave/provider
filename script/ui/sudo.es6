class SudoFields {
	constructor() {
		this.timers = new WeakMap();
		this.initialise();
		document.addEventListener("flux:after-render", () => this.initialise());
		document.addEventListener("submit", event => this.submit(event), {capture: true});
		window.addEventListener("pagehide", () => this.obscureAll());
		window.addEventListener("pageshow", () => this.initialise());
	}

	initialise() {
		for(const field of document.querySelectorAll("[data-sudo-enabled]")) {
			this.obscure(field);
		}

		for(const field of document.querySelectorAll("[data-sudo][data-copy]")) {
			const group = field.closest(".sudo-control");
			if(!group || group.querySelector("[data-sudo-copy]")) {
				continue;
			}

			const view = group.querySelector("[data-sudo-view]");
			const copy = view.cloneNode(true);
			copy.removeAttribute("data-sudo-view");
			copy.dataset.sudoCopy = field.id;
			copy.dataset.icon = "copy";
			copy.value = "copy";
			copy.querySelector("span").textContent = "Copy";
			copy.setAttribute("aria-label", view.getAttribute("aria-label").replace(/^View /, "Copy "));
			group.append(copy);
		}

		for(const indicator of document.querySelectorAll("[data-sudo-disable]")) {
			clearTimeout(this.timers.get(indicator));
			const remaining = Number(indicator.dataset.sudoUntil) * 1000 - Date.now();
			if(remaining <= 0) {
				indicator.hidden = true;
				continue;
			}
			this.timers.set(indicator, setTimeout(() => {
				indicator.hidden = true;
				this.obscureAll();
			}, remaining));
		}
	}

	obscure(field) {
		clearTimeout(this.timers.get(field));
		const mask = field.dataset.sudoEnabled;
		if(mask === undefined) {
			return;
		}

		this.setValue(field, mask);
		field.closest(".sudo-control")?.querySelector("[data-sudo-view]")?.setAttribute("aria-pressed", "false");
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

	async submit(event) {
		const button = event.submitter;
		if(!button?.matches("[data-sudo-view], [data-sudo-copy]")) {
			// Read-only credentials should not be submitted with unrelated changes.
			this.obscureAll();
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();
		const field = document.getElementById(button.dataset.sudoView || button.dataset.sudoCopy);
		if(this.pending) {
			return;
		}

		if(button.dataset.sudoView && button.getAttribute("aria-pressed") === "true") {
			this.obscure(field);
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

			const page = new DOMParser().parseFromString(await response.text(), "text/html");
			// WebEngine shares a one-use CSRF token across the page's forms.
			const token = page.querySelector('meta[name="csrf-token"]').content;
			document.querySelector('meta[name="csrf-token"]').content = token;
			for(const input of document.querySelectorAll('input[name="csrf-token"]')) {
				input.value = token;
			}

			const authorised = page.getElementById(field.id);
			if(!authorised?.hasAttribute("data-sudo-enabled")
				|| Number(authorised.dataset.sudoUntil) * 1000 <= Date.now()) {
				throw new Error("Sudo mode has expired. Please try again.");
			}

			const value = authorised.value ?? authorised.textContent;
			field.dataset.sudoEnabled = authorised.dataset.sudoEnabled;
			if(button.dataset.sudoCopy) {
				await navigator.clipboard.writeText(value);
			}
			else {
				this.setValue(field, value);
				button.setAttribute("aria-pressed", "true");
				clearTimeout(this.timers.get(field));
				this.timers.set(field, setTimeout(() => this.obscure(field),
					Number(authorised.dataset.sudoUntil) * 1000 - Date.now()));
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
