const storageKey = "authwave-color-scheme";
const modes = ["system", "light", "dark"];
const icons = {system: "sun-moon", light: "sun", dark: "moon"};
const root = document.documentElement;
const preference = matchMedia("(prefers-color-scheme: dark)");

function readPreference() {
	try {
		const saved = localStorage.getItem(storageKey);
		return modes.includes(saved) ? saved : "system";
	}
	catch {
		return "system";
	}
}

// Match www.authwave.com: the first click visibly changes the system's current scheme.
function sequence() {
	return matchMedia("(prefers-color-scheme: dark)").matches
		? ["system", "light", "dark"]
		: ["system", "dark", "light"];
}

let mode = readPreference();

function applyPreference() {
	// Keep the effective scheme available to provider branding.
	root.dataset.colorScheme = mode === "system" ? (preference.matches ? "dark" : "light") : mode;

	for(const button of document.querySelectorAll("[data-theme-toggle]")) {
		const order = sequence();
		const next = order[(order.indexOf(mode) + 1) % order.length];
		button.dataset.icon = icons[mode];
		button.dataset.themeMode = mode;
		button.querySelector("span").textContent = `${mode[0].toUpperCase()}${mode.slice(1)} theme`;
		button.setAttribute("aria-label", `Theme: ${mode}. Switch to ${next} theme.`);
		button.hidden = false;
	}
}

applyPreference();
document.addEventListener("click", event => {
	if(!event.target.closest("[data-theme-toggle]")) return;
	const order = sequence();
	mode = order[(order.indexOf(mode) + 1) % order.length];
	try {
		if(mode === "system") localStorage.removeItem(storageKey);
		else localStorage.setItem(storageKey, mode);
	}
	catch {
		// Apply the choice for this page even when browser storage is unavailable.
	}
	applyPreference();
});

// CSS follows live OS changes in System mode; keep the next-action label in sync.
preference.addEventListener("change", applyPreference);
document.addEventListener("flux:after-render", applyPreference);
window.addEventListener("storage", event => {
	if(event.key !== storageKey && event.key !== null) return;
	mode = readPreference();
	applyPreference();
});
