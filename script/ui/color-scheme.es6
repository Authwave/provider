const preference = window.matchMedia('(prefers-color-scheme: dark)');
const updateColourScheme = () => {
	document.documentElement.dataset["colorScheme"] = preference.matches ? "dark" : "light";
};
updateColourScheme();
preference.addEventListener("change", updateColourScheme);
