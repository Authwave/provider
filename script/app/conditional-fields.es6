function updateConditionalFields(form) {
	for(const group of form.querySelectorAll("[data-enable-when]")) {
		const control = form.elements.namedItem(group.dataset.enableWhen);
		group.disabled = !control?.checked;
		group.hidden = group.disabled;
	}
}

function initialiseConditionalFields() {
	for(const form of document.querySelectorAll("form:has([data-enable-when])")) {
		updateConditionalFields(form);
	}
}

document.addEventListener("change", event => {
	if(event.target.form) {
		updateConditionalFields(event.target.form);
	}
});
document.addEventListener("flux:after-render", initialiseConditionalFields);
initialiseConditionalFields();
