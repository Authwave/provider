function updateEmailDomain(form) {
	const suffix = form.querySelector("[data-email-domain]");
	if(!suffix) return;
	const value = form.elements.namedItem("providerHost").value.trim();
	let host = "";
	try {
		host = new URL("https://" + value).hostname.toLowerCase().replace(/\.$/, "");
	}
	catch {}
	suffix.value = "@" + (host || "login.example.com");
	const replyTo = form.elements.namedItem("emailReplyTo");
	const labels = host.split(".");
	if(labels.length > 2) labels.shift();
	const parent = host.includes(".") && !/^[0-9.]+$/.test(host)
		? "support@" + labels.join(".") : "";
	replyTo.placeholder = replyTo.dataset.replyToDefault || parent;
}

document.addEventListener("input", event => {
	if(event.target.name === "providerHost" && event.target.form) {
		updateEmailDomain(event.target.form);
	}
});
