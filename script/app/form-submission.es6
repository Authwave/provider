document.addEventListener("flux:before-request", event => {
	const headers = new Headers(event.detail.requestOptions.headers);
	headers.set("X-Authwave-Flux", "1");
	if(event.detail.historyState?.action === null) {
		headers.set("X-Authwave-Background", "1");
	}
	event.detail.requestOptions.headers = headers;
});

document.addEventListener("flux:before-render", event => {
	// Read the response token before its nodes are adopted into the current document.
	const incoming = event.detail?.updates?.find(update => update.newElement)?.newElement.ownerDocument;
	if(incoming) {
		updateCsrfTokens(incoming);
	}
});

export function updateCsrfTokens(incomingDocument) {
	const token = incomingDocument.querySelector('meta[name="csrf-token"]')?.content;
	if(!token) {
		return;
	}

	const meta = document.querySelector('meta[name="csrf-token"]');
	if(meta) {
		meta.content = token;
	}

	const incomingTokens = incomingDocument.querySelectorAll('input[name="csrf-token"]');
	const currentTokens = document.querySelectorAll('input[name="csrf-token"]');

	for(let index = 0; index < Math.min(incomingTokens.length, currentTokens.length); index++) {
		currentTokens[index].value = incomingTokens[index].value;
	}
}

document.addEventListener("flux:after-render", () => {
	const link = document.querySelector("a[data-client-redirect]");
	if(link) {
		window.location.assign(link.href);
	}
});
