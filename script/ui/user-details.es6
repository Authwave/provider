// Native popovers remain usable without scripting; history makes them bookmarkable.
function restoreUserDetails(event) {
	const user = new URL(location.href).searchParams.get("user");

	if(user === null && event?.type !== "popstate") {
		return;
	}
	const selected = user === null ? null : document.getElementById(`user-details-${user}`);

	for(const popup of document.querySelectorAll(".user-details:popover-open")) {
		if(popup !== selected) {
			popup.hidePopover();
		}
	}

	if(selected && !selected.matches(":popover-open")) {
		selected.showPopover();
	}
}

document.addEventListener("toggle", event => {
	if(!event.target.matches(".user-details[popover]") || !event.target.isConnected) {
		return;
	}

	const open = document.querySelector(".user-details:popover-open");
	const url = new URL(location.href);

	if(open) {
		url.searchParams.set("user", open.querySelector('input[name="id"]').value);
		url.searchParams.set("application", document.querySelector('.user-filters input[name="application"]').value);
	}
	else {
		url.searchParams.delete("user");
	}

	if(url.href !== location.href) {
		history.pushState(history.state, "", url);
	}
}, {capture: true});

window.addEventListener("popstate", restoreUserDetails);
document.addEventListener("flux:after-render", restoreUserDetails);
restoreUserDetails();
