// Native modal UI; unsupported browsers retain the server confirmation form.
let pending;
const approvedForms = new WeakSet();
let expiryTimer;

document.addEventListener("submit", event => {
	const button = event.submitter;
	if(!button?.matches("[data-confirm-delete]")) return;
	if(!button.dataset.confirmDelete || !button.dataset.deleteName) return;
	const form = event.target;
	if(approvedForms.delete(form)) return;
	const dialog = document.getElementById("delete-dialog");
	if(typeof dialog?.showModal !== "function") return;
	event.preventDefault();
	event.stopImmediatePropagation();
	if(pending) return;
	pending = {form, button, identifier: button.dataset.confirmDelete};
	let name = button.dataset.deleteName;

	if(button.dataset.deleteNameFrom) {
		name = document.getElementById(button.dataset.deleteNameFrom).textContent;
	}

	dialog.querySelector("h2").textContent = `Confirm deleting ${name}`;

	if(button.hasAttribute("data-sudo-required")) {
		const remaining = Number(button.dataset.sudoUntil) * 1000 - Date.now();
		const expire = () => {
			const remaining = Number(button.dataset.sudoUntil) * 1000 - Date.now();

			if(remaining <= 0) {
				dialog.close("cancel");
				return;
			}

			expiryTimer = setTimeout(expire, remaining);
		};
		expiryTimer = setTimeout(expire, Math.max(0, remaining));
	}
	dialog.returnValue = "cancel";
	dialog.showModal();
}, {capture: true});

document.addEventListener("close", event => {
	if(event.target.id !== "delete-dialog" || !pending) return;
	clearTimeout(expiryTimer);
	event.target.querySelector("h2").textContent = "";
	const {form, button, identifier} = pending;
	pending = null;
	if(!form.isConnected || !button.isConnected || button.disabled) return;
	if(event.target.returnValue !== "confirm") {
		button.focus();
		return;
	}
	const confirmation = document.createElement("input");
	confirmation.type = "hidden";
	confirmation.name = "confirmDelete";
	confirmation.value = identifier;
	form.append(confirmation);
	approvedForms.add(form);
	try {
		form.requestSubmit(button);
	}
	finally {
		approvedForms.delete(form);
		setTimeout(() => confirmation.remove(), 0);
	}
}, {capture: true});
