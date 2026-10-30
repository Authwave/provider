// App behaviour: keep the current field and its primary action together above
// the keyboard, without moving focus or changing the shared panel layout.
const viewport = window.visualViewport;
const textInputTypes = new Set([
	"text", "email", "password", "search", "tel", "url", "number",
]);
let resizeFrame = null;

function revealPrimaryAction() {
	resizeFrame = null;
	const field = document.activeElement;
	const isTextInput = field instanceof HTMLInputElement
		&& textInputTypes.has(field.type);

	if(!isTextInput && !(field instanceof HTMLTextAreaElement)) {
		return;
	}

	if(!field.form || field.disabled || field.readOnly) {
		return;
	}

	// A zoom gesture also resizes the visual viewport; leave it to the user.
	if(viewport && viewport.scale !== 1) {
		return;
	}

	const button = Array.from(field.form.elements).find(element => {
		const isButton = element instanceof HTMLButtonElement
			|| (element instanceof HTMLInputElement
				&& ["submit", "button", "image"].includes(element.type));
		return isButton && element.classList.contains("primary")
			&& !element.matches(':disabled, [aria-disabled="true"]')
			&& element.getClientRects().length > 0
			&& getComputedStyle(element).visibility === "visible";
	});

	if(!button) {
		return;
	}

	// Bounding rectangles use layout-viewport coordinates. The visual
	// viewport can be offset within it when the browser pans for the keyboard.
	const top = viewport ? viewport.offsetTop : 0;
	const bottom = top + (viewport ? viewport.height : window.innerHeight);
	const fieldRect = field.getBoundingClientRect();
	const buttonRect = button.getBoundingClientRect();

	if(buttonRect.top >= top && buttonRect.bottom <= bottom) {
		return;
	}

	const distance = buttonRect.bottom > bottom
		? buttonRect.bottom - bottom
		: buttonRect.top - top;

	// Only scroll if the proposed position contains both complete elements.
	if(fieldRect.top - distance < top || fieldRect.bottom - distance > bottom
		|| buttonRect.top - distance < top || buttonRect.bottom - distance > bottom) {
		return;
	}

	window.scrollBy({top: distance, behavior: "instant"});
}

(viewport || window).addEventListener("resize", () => {
	if(resizeFrame === null) {
		resizeFrame = requestAnimationFrame(revealPrimaryAction);
	}
});
