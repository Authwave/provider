// Capture duplicate submissions before they reach Flux's form listeners.
document.addEventListener("submit", event => {
	if(event.target.matches("form[data-flux].flux-form-waiting")) {
		event.preventDefault();
		event.stopImmediatePropagation();
	}
}, {capture: true});

const lockedForms = new WeakSet();

// Keep fields focusable and their values intact while Flux is submitting.
document.addEventListener("flux:before-request", () => {
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

		const observer = new MutationObserver(() => {
			if(form.classList.contains("flux-form-waiting")) {
				return;
			}
			fields.forEach(({field, wasReadOnly}) => {
				field.readOnly = wasReadOnly;
			});
			lockedForms.delete(form);
			observer.disconnect();
		});
		observer.observe(form, {attributes: true, attributeFilter: ["class"]});
	});
});
