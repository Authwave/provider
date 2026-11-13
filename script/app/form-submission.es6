const pendingSubmissions = new Map();

function submitWhenReady(formId) {
	const submission = pendingSubmissions.get(formId);
	const form = document.getElementById(formId);
	if(!submission || !form) {
		pendingSubmissions.delete(formId);
		return;
	}
	if(form.matches(".flux-form-waiting") || form.querySelector(".input-changed")) {
		setTimeout(() => submitWhenReady(formId), 100);
		return;
	}
	pendingSubmissions.delete(formId);
	const button = Array.from(form.elements).find(element =>
		element instanceof HTMLButtonElement && element.name === submission.name
		&& element.value === submission.value);
	if(button) form.requestSubmit(button);
}

// Preserve an explicit action clicked while a preview is still completing.
document.addEventListener("submit", event => {
	const form = event.target;
	if(!(form instanceof HTMLFormElement)) return;
	const previewButton = form.querySelector('button[name="do"][value="preview-deployment"]');
	const busy = form.classList.contains("flux-form-waiting");
	const changing = previewButton && form.querySelector(".input-changed");
	if(!busy && !changing) return;
	event.preventDefault();
	event.stopImmediatePropagation();
	const button = event.submitter;
	const waitingButton = form.querySelector("button.flux-button-waiting");
	const previewing = previewButton && (!waitingButton || waitingButton === previewButton);
	const formId = form.getAttribute("id");
	if(formId && previewing && button) {
		const alreadyQueued = pendingSubmissions.has(formId);
		pendingSubmissions.set(formId, {name: button.name, value: button.value});
		if(!alreadyQueued) setTimeout(() => submitWhenReady(formId), 100);
	}
}, {capture: true});

const lockedForms = new WeakSet();

// Keep fields focusable and their values intact while Flux is submitting.
document.addEventListener("flux:before-request", event => {
	const headers = new Headers(event.detail.requestOptions.headers);
	headers.set("X-Authwave-Flux", "1");
	if(event.detail.historyState?.action === null) {
		headers.set("X-Authwave-Background", "1");
	}
	event.detail.requestOptions.headers = headers;
	document.querySelectorAll("form.flux-form-waiting").forEach(form => {
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
document.addEventListener("flux:before-render", event => {
	// Read the response token before its nodes are adopted into the current document.
	const incoming = event.detail?.updates?.find(update => update.newElement)?.newElement.ownerDocument;
	if(incoming) {
		updateCsrfTokens(incoming);
	}

	const active = document.activeElement;
	if(active instanceof HTMLInputElement && active.selectionStart === null) {
		active.form?.querySelector('button[type="submit"]')?.focus();
	}
});

export function updateCsrfTokens(incomingDocument) {
	const token = incomingDocument.querySelector('meta[name="csrf-token"]')?.content;
	if(!token) {
		return;
	}

	const meta = document.querySelector('meta[name="csrf-token"]');
	if(meta) {
		meta.content = token;
	}

	const incomingTokens = incomingDocument.querySelectorAll('input[name="csrf-token"]');
	const currentTokens = document.querySelectorAll('input[name="csrf-token"]');

	for(let index = 0; index < Math.min(incomingTokens.length, currentTokens.length); index++) {
		currentTokens[index].value = incomingTokens[index].value;
	}
}

document.addEventListener("flux:after-render", () => {
	const link = document.querySelector("a[data-client-redirect]");
	if(link) {
		window.location.assign(link.href);
	}
});
