document.querySelectorAll("security-code").forEach(initialiseSecurityCode);

function initialiseSecurityCode(element) {
	const template = element.querySelector("template");
	if(!template) {
		return;
	}

	const fallback = element.querySelector(".security-code-fallback");
	const valueInput = fallback.querySelector("input");
	const shouldFocus = document.activeElement === valueInput;
	template.replaceWith(template.content.cloneNode(true));
	const inputs = Array.from(element.querySelectorAll(".security-code-digits input"));
	const confirm = Array.from(valueInput.form?.elements || []).find(control =>
		control instanceof HTMLButtonElement && control.type === "submit"
		&& control.classList.contains("primary")
	);
	const digits = value => value.normalize("NFKC").replace(/[^0-9]/g, "");
	const isComplete = () => inputs.every(input => /^[0-9]$/.test(input.value));

	const focus = index => {
		inputs[index].focus();
	};
	const sync = () => {
		valueInput.value = inputs.map(input => input.value).join("");
	};
	const fill = (value, index) => {
		const code = digits(value);
		if(!code) {
			return;
		}
		// A full-code paste/autofill replaces the group from any cell.
		const start = code.length >= inputs.length ? 0 : index;
		const length = Math.min(code.length, inputs.length - start);
		for(let offset = 0; offset < length; offset++) {
			inputs[start + offset].value = code[offset];
		}
		sync();
		if(isComplete() && confirm && !confirm.matches(':disabled, [aria-disabled="true"]')
			&& confirm.getClientRects().length > 0 && getComputedStyle(confirm).visibility === "visible") {
			confirm.focus();
			return;
		}
		const next = start + length;
		if(next < inputs.length) {
			focus(next);
		}
		else {
			const last = inputs[inputs.length - 1];
			last.focus();
			last.setSelectionRange(last.value.length, last.value.length);
		}
	};

	const initial = digits(valueInput.value).slice(0, inputs.length);
	inputs.forEach((input, index) => {
		input.value = initial[index] || "";
		input.defaultValue = input.value;
		const backspace = () => {
			if(!input.value && index > 0) {
				inputs[index - 1].value = "";
				focus(index - 1);
			}
			else {
				input.value = "";
			}
			sync();
		};
		input.addEventListener("focus", () => input.select());
		input.addEventListener("beforeinput", event => {
			// Some mobile keyboards send deletion without a Backspace key event.
			if(event.inputType === "deleteContentBackward" && event.cancelable && !event.isComposing) {
				event.preventDefault();
				backspace();
			}
		});
		input.addEventListener("input", event => {
			if(event.isComposing) {
				return;
			}
			input.value = digits(event.inputType === "insertText" && event.data?.length === 1
				? event.data : input.value);
			if(input.value) {
				fill(input.value, index);
			}
			else {
				sync();
			}
		});
		input.addEventListener("compositionend", () => {
			input.value = digits(input.value);
			if(input.value) {
				fill(input.value, index);
			}
			else {
				sync();
			}
		});
		input.addEventListener("paste", event => {
			const text = event.clipboardData?.getData("text");
			if(text !== undefined) {
				event.preventDefault();
				fill(text, index);
			}
		});
		input.addEventListener("keydown", event => {
			if(event.isComposing || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
				return;
			}
			switch(event.key) {
			case "ArrowLeft":
				event.preventDefault();
				focus(Math.max(0, index - 1));
				break;
			case "ArrowRight":
				event.preventDefault();
				focus(Math.min(inputs.length - 1, index + 1));
				break;
			case "Backspace":
				event.preventDefault();
				backspace();
				break;
			}
		});
	});

	valueInput.type = "hidden";
	valueInput.required = false;
	fallback.hidden = true;
	sync();
	valueInput.form?.addEventListener("formdata", event => {
		sync();
		event.formData.set(valueInput.name, valueInput.value);
	});
	valueInput.form?.addEventListener("reset", () => {
		requestAnimationFrame(sync);
	});
	confirm?.addEventListener("keydown", event => {
		if(event.key !== "Backspace" || event.isComposing || event.altKey
			|| event.ctrlKey || event.metaKey || event.shiftKey || !isComplete()) {
			return;
		}
		event.preventDefault();
		inputs[inputs.length - 1].value = "";
		sync();
		focus(inputs.length - 1);
	});
	if(shouldFocus) {
		focus(Math.max(0, inputs.findIndex(input => !input.value)));
	}
}
