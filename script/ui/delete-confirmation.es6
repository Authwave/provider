// Native modal UI; unsupported browsers retain the server confirmation form.
let pending;
const approvedForms = new WeakSet();

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
	dialog.querySelector("h2").textContent = `Confirm deleting ${button.dataset.deleteName}`;
	dialog.returnValue = "cancel";
	dialog.showModal();
}, {capture: true});

document.addEventListener("close", event => {
	if(event.target.id !== "delete-dialog" || !pending) return;
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
