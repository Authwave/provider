// Focus only for a primary input device that supports hover and precise pointing.
// Touch users can open the keyboard by tapping the field themselves.
function autofocus(force = false) {
	if(!window.matchMedia("(hover: hover) and (pointer: fine)").matches) {
		return;
	}
	if(!force && document.activeElement && document.activeElement !== document.body) {
		return;
	}
	const input = Array.from(document.querySelectorAll("[data-autofocus]")).find(element =>
		!element.matches(":disabled, [readonly], [type=hidden]")
		&& element.getClientRects().length > 0
		&& getComputedStyle(element).visibility === "visible"
	);
	input?.focus();
}

autofocus();
let renderedPath = window.location.pathname;
document.addEventListener("flux:after-render", () => {
	const path = window.location.pathname;
	if(path === renderedPath) {
		return;
	}
	renderedPath = path;
	// Flux restores focus again after this event. Focus the new screen's
	// input once that work and component initialisation have finished.
	queueMicrotask(() => autofocus(true));
});
