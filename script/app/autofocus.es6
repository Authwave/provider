// Focus only for a primary input device that supports hover and precise pointing.
// Touch users can open the keyboard by tapping the field themselves.
if(window.matchMedia("(hover: hover) and (pointer: fine)").matches
	&& (!document.activeElement || document.activeElement === document.body)) {
	const input = Array.from(document.querySelectorAll("[data-autofocus]")).find(element =>
		!element.matches(":disabled, [readonly], [type=hidden]")
		&& element.getClientRects().length > 0
		&& getComputedStyle(element).visibility === "visible"
	);
	input?.focus();
}
