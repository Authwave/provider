// Capture duplicate submissions before they reach Flux's form listeners.
document.addEventListener("submit", event => {
	if(event.target.matches("form[data-flux].flux-form-waiting")) {
		event.preventDefault();
		event.stopImmediatePropagation();
	}
}, {capture: true});

const lockedForms = new WeakSet();

// Keep fields focusable and their values intact while Flux is submitting.
document.addEventListener("flux:before-request", event => {
	const headers = new Headers(event.detail.requestOptions.headers);
	headers.set("X-Authwave-Flux", "1");
	event.detail.requestOptions.headers = headers;
	document.querySelectorAll("form[data-flux].flux-form-waiting").forEach(form => {
		if(lockedForms.has(form)) {
			return;
		}
		const fields = Array.from(form.querySelectorAll("input, textarea"), field => {
			const wasReadOnly = field.readOnly;
			field.readOnly = true;
			return {field, wasReadOnly};
		});
		lockedForms.add(form);
		form.setAttribute("aria-busy", "true");

		const observer = new MutationObserver(() => {
			if(form.classList.contains("flux-form-waiting")) {
				return;
			}
			fields.forEach(({field, wasReadOnly}) => {
				field.readOnly = wasReadOnly;
			});
			lockedForms.delete(form);
			form.removeAttribute("aria-busy");
			observer.disconnect();
		});
		observer.observe(form, {attributes: true, attributeFilter: ["class"]});
	});
});

// This Flux version tries to restore text selections even on email inputs,
// whose selectionStart is null. Move focus to the action before replacement.
document.addEventListener("flux:before-render", () => {
	const active = document.activeElement;
	if(active instanceof HTMLInputElement && active.selectionStart === null) {
		active.form?.querySelector('button[type="submit"]')?.focus();
	}
});

document.addEventListener("flux:after-render", () => {
	const link = document.querySelector("a[data-client-redirect]");
	if(link) {
		window.location.assign(link.href);
	}
});
