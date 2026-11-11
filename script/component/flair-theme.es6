// The interface defaults to Bright. ?flairTheme=base selects monochrome.
// Keep this presentation preference in this origin's localStorage, not a cookie.
const storageKey = "authwave-flair-theme";
const themes = ["base", "bright"];
const selected = new URL(location.href).searchParams.get("flairTheme");
let theme = themes.includes(selected) ? selected : "bright";
try {
	if(themes.includes(selected)) {
		localStorage.setItem(storageKey, selected);
	}
	else {
		const saved = localStorage.getItem(storageKey);
		if(themes.includes(saved)) theme = saved;
	}
}
catch {
	// Login and explicit theme selection still work if browser storage is blocked.
}
const applyTheme = () => {
	document.documentElement.dataset.flairTheme = theme;
};
applyTheme();
document.addEventListener("flux:after-render", applyTheme);
