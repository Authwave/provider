const selector = ".toolbar details.disclosure-menu";
const initialised = new WeakSet();

function initialiseDropdown(dropdown) {
	if(initialised.has(dropdown)) return;
	initialised.add(dropdown);
	dropdown.addEventListener("toggle", event => {
		const opened = event.currentTarget;
		if(!opened.open) return;
		document.querySelectorAll(selector).forEach(other => {
			if(other !== opened) other.open = false;
		});
	});
}

const initialise = () => document.querySelectorAll(selector).forEach(initialiseDropdown);
initialise();
document.addEventListener("flux:after-render", initialise);

document.addEventListener("click", event => {
	document.querySelectorAll(selector).forEach(dropdown => {
		if(dropdown.open && !dropdown.contains(event.target)) dropdown.open = false;
	});
});

document.addEventListener("keydown", event => {
	if(event.key !== "Escape") return;
	document.querySelectorAll(selector).forEach(dropdown => {
		if(!dropdown.open) return;
		event.preventDefault();
		if(dropdown.contains(document.activeElement)) dropdown.querySelector("summary").focus();
		dropdown.open = false;
	});
});
