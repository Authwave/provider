function updateConditionalFields(form) {
	for(const group of form.querySelectorAll("[data-enable-when]")) {
		const control = form.elements.namedItem(group.dataset.enableWhen);
		group.disabled = !control?.checked;
		group.hidden = group.disabled && !group.hasAttribute("data-keep-visible");
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

// Checkboxes have no native readonly behaviour; cancelling activation preserves their state.
document.addEventListener("click", event => {
	if(event.target.matches('input[type="checkbox"][aria-readonly="true"]')) {
		event.preventDefault();
	}
});
