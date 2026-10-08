// The interface defaults to Bright. ?flairTheme=base selects monochrome.
const selected = new URL(location.href).searchParams.get("flairTheme");
if(["base", "bright"].includes(selected)) {
	document.documentElement.dataset.flairTheme = selected;
}
