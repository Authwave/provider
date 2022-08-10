export function go() {
	initFormSubmitGuard();
}

// TODO: Load these functions from elsewhere when it becomes a problem.
function initFormSubmitGuard() {
	document.querySelectorAll("form[method=post]").forEach(form => {
		form.addEventListener("submit", e => {
			form.classList.add("loading");

			form.querySelectorAll("input,button").forEach(el => {
				el.readonly = true;
			});
		});
	});
}
