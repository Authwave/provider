document.addEventListener("flux:before-render", event => {
	const current = document.querySelector("#templates [data-template-preview]");
	if(!current) return;
	for(const update of event.detail?.updates ?? []) {
		const section = update.newElement;
		const preview = section?.querySelector("[data-template-preview]");
		if(preview) preview.open = current.open;
	}
});
