// Run after building the assets: node test/ui/browser.mjs (Node 22+ and Chromium).
import assert from "node:assert/strict";
import {assertPageLayout} from "./page-layout.mjs";
import {spawn, execFileSync} from "node:child_process";
import {mkdtemp, readFile, rm, mkdir, writeFile} from "node:fs/promises";
import {createServer} from "node:http";
import {tmpdir} from "node:os";
import {join, resolve} from "node:path";
import {setTimeout as delay} from "node:timers/promises";

const root = resolve(import.meta.dirname, "../..");
const temporary = await mkdtemp(join(tmpdir(), "authwave-ui-"));
let browser, socket, server;
let pendingPost = null;
let finishPost = null;
let nextRedirect = null;
const responseFixtures = new Map();
const postRequests = [];
try {
	execFileSync("php", ["test/ui/fixtures.php", temporary], {cwd: root});
	server = createServer(async (request, response) => {
		const path = new URL(request.url, "http://localhost").pathname;
		let file;
		if(/^\/(index|authenticate|security-check|success|access-denied)\.html$/.test(path)) file = join(temporary, path);
		else if(path === "/style.css" || path === "/script.js") file = join(root, "www", path);
		else if(path.startsWith("/asset/") && !path.includes("..")) file = join(root, path);
		const types = {css: "text/css", js: "text/javascript", html: "text/html", svg: "image/svg+xml", woff2: "font/woff2"};
		if(request.method === "POST") {
			let body = "";
			for await(const chunk of request) body += chunk;
			postRequests.push({path, body, headers: request.headers});
			await pendingPost;
			if(nextRedirect) {
				const location = nextRedirect;
				nextRedirect = null;
				response.writeHead(303, {Location: location}).end();
				return;
			}
		}
		if(responseFixtures.has(path)) {
			const {status = 200, html} = responseFixtures.get(path);
			response.writeHead(status, {"Content-Type": "text/html"}).end(html);
			return;
		}
		try {
			const data = await readFile(file || "");
			response.writeHead(200, {"Content-Type": types[file.split(".").pop()] || "application/octet-stream"});
			response.end(data);
		} catch { response.writeHead(404).end(); }
	});
	await new Promise(resolve => server.listen(0, "127.0.0.1", resolve));
	const origin = `http://127.0.0.1:${server.address().port}`;
	browser = spawn(process.env.CHROMIUM || "chromium", ["--headless", "--no-sandbox", "--disable-gpu", "--no-first-run", "--remote-debugging-port=0", `--user-data-dir=${temporary}/profile`, "about:blank"], {stdio: "ignore"});
	let port;
	for(let attempts = 0; attempts < 100; attempts++) {
		try { port = (await readFile(`${temporary}/profile/DevToolsActivePort`, "utf8")).split("\n")[0]; break; }
		catch { await delay(100); }
	}
	assert.ok(port, "Chromium must start");
	const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
	socket = new WebSocket(targets.find(target => target.type === "page").webSocketDebuggerUrl);
	await new Promise(resolve => socket.addEventListener("open", resolve, {once: true}));
	let id = 0;
	const pending = new Map(), failures = [];
	socket.addEventListener("message", event => {
		const message = JSON.parse(event.data);
		if(message.id) {
			const {resolve, reject} = pending.get(message.id);
			pending.delete(message.id);
			message.error ? reject(new Error(JSON.stringify(message.error))) : resolve(message.result);
		}
		if(message.method === "Runtime.exceptionThrown") failures.push(message.params.exceptionDetails.text);
		if(message.method === "Network.responseReceived" && message.params.response.status >= 400
			&& !message.params.response.url.endsWith("favicon.ico")
			&& !message.params.response.url.endsWith("/denied-step.html")) failures.push(message.params.response.url);
	});
	const send = (method, params = {}) => new Promise((resolve, reject) => {
		pending.set(++id, {resolve, reject});
		socket.send(JSON.stringify({id, method, params}));
	});
	const evaluate = async expression => {
		const result = await send("Runtime.evaluate", {expression, returnByValue: true, awaitPromise: true});
		assert.ok(!result.exceptionDetails, JSON.stringify(result.exceptionDetails));
		return result.result.value;
	};
	const navigate = async page => {
		await send("Page.navigate", {url: `${origin}/${page}.html`});
		for(let attempts = 0; attempts < 100; attempts++) {
			if(await evaluate(`location.pathname === '/${page}.html' && document.readyState === 'complete'`)) return;
			await delay(50);
		}
		assert.fail(`Page did not load: ${page}`);
	};
	const waitFor = async expression => {
		for(let attempts = 0; attempts < 100; attempts++) {
			if(await evaluate(expression)) return;
			await delay(20);
		}
		assert.fail(`Condition not met: ${expression}`);
	};
	await send("Page.enable");
	await send("Runtime.enable");
	await send("Network.enable");
	await send("Emulation.setFocusEmulationEnabled", {enabled: true});
	for(const width of [280, 320, 390, 640, 1280]) {
		await send("Emulation.setDeviceMetricsOverride", {width, height: 844, deviceScaleFactor: 1, mobile: width < 600});
		for(const mode of ["light", "dark"]) {
			await send("Emulation.setEmulatedMedia", {features: [{name: "prefers-color-scheme", value: mode}]});
			for(const page of ["index", "authenticate", "security-check", "success", "access-denied"]) {
				await navigate(page);
				assert.equal(await evaluate("document.documentElement.dataset.colorScheme"), mode);
				assert.equal(await evaluate("getComputedStyle(document.documentElement).getPropertyValue('--pal--theme').trim()"), mode === "light" ? "#123456" : "#abcdef");
				assert.equal(await evaluate("getComputedStyle(document.documentElement).backgroundColor"), mode === "light" ? "rgb(240, 241, 242)" : "rgb(16, 17, 18)");
				assert.equal(await evaluate("document.documentElement.scrollWidth <= innerWidth"), true, `${page} ${width}: overflow`);
				await assertPageLayout(evaluate, `${page}, ${width}px, ${mode}`, width >= 640);
				assert.equal(await evaluate("document.querySelector('.logo').naturalWidth > 0"), true);
				assert.equal(await evaluate("new URL(document.querySelector('.logo').currentSrc).search"), `?${mode}`, `${page}: ${mode} logo`);
				const dimensions = await evaluate("({main:document.querySelector('main').getBoundingClientRect().width, viewport:innerWidth})");
				assert.ok(width < 600 ? dimensions.main === dimensions.viewport : dimensions.main < dimensions.viewport, `${page}: panel width`);
				if(process.env.UI_SCREENSHOT_DIR) {
					await mkdir(process.env.UI_SCREENSHOT_DIR, {recursive: true});
					const screenshot = await send("Page.captureScreenshot", {format: "png", captureBeyondViewport: true});
					await writeFile(join(process.env.UI_SCREENSHOT_DIR, `${page}-${width}-${mode}.png`), Buffer.from(screenshot.data, "base64"));
				}
			}
		}
	}
	// Change the system preference on the same page: palette and picture must agree.
	await navigate("index");
	for(const mode of ["light", "dark"]) {
		await send("Emulation.setEmulatedMedia", {features: [{name: "prefers-color-scheme", value: mode}]});
		for(let attempt = 0; attempt < 100; attempt++) {
			if(await evaluate(`document.documentElement.dataset.colorScheme === '${mode}' && document.querySelector('.logo').currentSrc && new URL(document.querySelector('.logo').currentSrc).search === '?${mode}'`)) break;
			await delay(20);
		}
		assert.equal(await evaluate("document.documentElement.dataset.colorScheme"), mode);
		assert.equal(await evaluate("new URL(document.querySelector('.logo').currentSrc).search"), `?${mode}`);
		assert.equal(await evaluate("getComputedStyle(document.querySelector('button.primary')).backgroundColor"), mode === "light" ? "rgb(18, 52, 86)" : "rgb(171, 205, 239)");
		assert.equal(await evaluate("getComputedStyle(document.querySelector('button.primary')).color"), mode === "light" ? "rgb(255, 255, 255)" : "rgb(0, 0, 0)");
	}
	for(const mode of ["light", "dark"]) {
		await send("Emulation.setEmulatedMedia", {features: [{name: "prefers-color-scheme", value: mode}]});
		await navigate("security-check");
		const branding = await evaluate(`(() => {
			const root = document.documentElement;
			root.style.setProperty('--pal--theme', '#123456');
			root.style.setProperty('--pal--theme-secondary', '#abcdef');
			const button = document.querySelector('button.primary');
			const code = document.querySelector('.security-code-digits input');
			code.style.transition = 'none';
			code.focus();
			const style = getComputedStyle(button);
			const background = style.backgroundColor;
			const outline = getComputedStyle(code).outlineColor;
			const codeBorder = getComputedStyle(code).borderTopColor;
			const tint = style.getPropertyValue('--pal--background-active');
			root.style.setProperty('--pal--theme-secondary', '#fedcba');
			return {background, outline, codeBorder,
				primaryUnchanged: background === style.backgroundColor,
				tintChanged: tint !== style.getPropertyValue('--pal--background-active')};
		})()`);
		assert.deepEqual(branding, {
			background: "rgb(18, 52, 86)", outline: "rgb(171, 205, 239)",
			codeBorder: "rgb(171, 205, 239)", primaryUnchanged: true, tintChanged: true,
		}, `${mode}: independent primary and secondary colours`);
	}
	await navigate("security-check");
	assert.equal(await evaluate("document.querySelectorAll('.security-code-digits input').length"), 5);
	await evaluate("document.querySelector('.security-code-digits input').focus()");
	for(const digit of "01234") await send("Input.insertText", {text: digit});
	assert.equal(await evaluate("document.activeElement.value"), "confirm");
	await evaluate(`document.querySelector('form').addEventListener('submit', event => {
		event.preventDefault();
		event.stopImmediatePropagation();
		window.submitted = Object.fromEntries(new FormData(event.target, event.submitter));
	}, {capture: true})`);
	await send("Input.dispatchKeyEvent", {type: "keyDown", key: "Enter", code: "Enter", text: "\r", unmodifiedText: "\r", windowsVirtualKeyCode: 13});
	await send("Input.dispatchKeyEvent", {type: "keyUp", key: "Enter", code: "Enter", windowsVirtualKeyCode: 13});
	assert.deepEqual(await evaluate("window.submitted"), {token: "01234", do: "confirm"});
	assert.equal(await evaluate("new FormData(document.querySelector('form')).get('token')"), "01234");
	assert.equal(await evaluate("document.querySelector('form').checkValidity()"), true);
	await send("Input.dispatchKeyEvent", {type: "keyDown", key: "Backspace", code: "Backspace", windowsVirtualKeyCode: 8});
	await send("Input.dispatchKeyEvent", {type: "keyUp", key: "Backspace", code: "Backspace", windowsVirtualKeyCode: 8});
	assert.equal(await evaluate("document.activeElement.getAttribute('aria-label')"), "Digit 5 of 5");
	assert.equal(await evaluate("new FormData(document.querySelector('form')).get('token')"), "0123");
	assert.equal(await evaluate("document.querySelector('form').checkValidity()"), false);
	await evaluate(`(() => {
		const data = new DataTransfer(); data.setData('text/plain', '98765');
		document.activeElement.dispatchEvent(new ClipboardEvent('paste', {clipboardData:data, bubbles:true, cancelable:true}));
	})()`);
	assert.equal(await evaluate("new FormData(document.querySelector('form')).get('token')"), "98765");
	assert.equal(await evaluate("document.activeElement.value"), "confirm");
	// Hold real Flux requests open so the submitting state can be inspected.
	for(const [page, button] of [["index", "continue"], ["authenticate", "password"], ["authenticate", "link"]]) {
		await navigate(page);
		await evaluate(`document.querySelector('input').value = ${JSON.stringify(page === "index" ? "test@example.test" : "test-password-123")}`);
		pendingPost = new Promise(resolve => { finishPost = resolve; });
		const count = postRequests.length;
		await evaluate(`(() => {
			const form = document.querySelector('form');
			const button = document.querySelector('button[value="${button}"]');
			form.requestSubmit(button);
			form.requestSubmit(button);
		})()`);
		await waitFor("!!document.querySelector('button.flux-button-waiting')");
		assert.equal(await evaluate("document.querySelector('input').readOnly"), true);
		const submittedValue = page === "index" ? "test@example.test" : "test-password-123";
		await evaluate("document.querySelector('input').focus()");
		assert.equal(await evaluate("document.activeElement === document.querySelector('input')"), true);
		assert.equal(await evaluate("getComputedStyle(document.querySelector('input')).cursor"), "wait");
		assert.equal(await evaluate("getComputedStyle(document.querySelector('.flux-button-waiting')).cursor"), "wait");
		assert.notEqual(await evaluate("getComputedStyle(document.querySelector('input')).pointerEvents"), "none");
		await send("Input.insertText", {text: "accidental edit"});
		assert.equal(await evaluate("document.querySelector('input').value"), submittedValue);
		assert.equal(await evaluate("new FormData(document.querySelector('form')).get(document.querySelector('input').name)"), submittedValue);
		// Other submit buttons and implicit keyboard submission must also be blocked.
		await evaluate(`document.querySelectorAll('button[type=submit]').forEach(button => button.click()); document.querySelector('form').requestSubmit(); document.querySelector('input').focus()`);
		await send("Input.dispatchKeyEvent", {type: "keyDown", key: "Enter", code: "Enter", text: "\r", windowsVirtualKeyCode: 13});
		await send("Input.dispatchKeyEvent", {type: "keyUp", key: "Enter", code: "Enter", windowsVirtualKeyCode: 13});
		assert.equal(await evaluate("document.querySelector('.flux-button-waiting').value"), button);
		assert.equal(await evaluate("document.querySelectorAll('.flux-button-waiting').length"), 1);
		assert.equal(await evaluate("getComputedStyle(document.querySelector('.flux-button-waiting'), '::after').maskImage.includes('loader-4.svg')"), true);
		assert.equal(await evaluate("getComputedStyle(document.querySelector('.flux-button-waiting'), '::after').position"), "absolute");
		assert.equal(await evaluate("getComputedStyle(document.querySelector('.flux-button-waiting'), '::after').animationName"), "flux-loader-spin");
		assert.equal(await evaluate("getComputedStyle(document.querySelector('.flux-button-waiting'), '::after').transitionDuration"), "1s");
		assert.equal(await evaluate("getComputedStyle(document.querySelector('.flux-button-waiting'), '::after').transitionDelay"), "0s");
		assert.equal(await evaluate("getComputedStyle(document.querySelector('.flux-button-waiting > span')).transitionDuration"), "1s");
		if(page === "authenticate") {
			assert.equal(await evaluate(`getComputedStyle(document.querySelector('.flux-button-waiting'), '::before').maskImage.includes('${button === "password" ? "login" : "mail"}.svg')`), true);
		}
		await waitFor("getComputedStyle(document.querySelector('.flux-button-waiting > span')).opacity === '0' && getComputedStyle(document.querySelector('.flux-button-waiting'), '::after').opacity === '1'");
		await send("Emulation.setEmulatedMedia", {features: [{name: "prefers-reduced-motion", value: "reduce"}]});
		assert.equal(await evaluate("getComputedStyle(document.querySelector('.flux-button-waiting'), '::after').animationName"), "none");
		assert.equal(await evaluate("getComputedStyle(document.querySelector('.flux-button-waiting'), '::after').transitionDuration"), "0s");
		await send("Emulation.setEmulatedMedia", {features: []});
		finishPost();
		finishPost = null;
		pendingPost = null;
		await waitFor("!document.querySelector('.flux-form-waiting, .flux-button-waiting')");
		assert.equal(await evaluate("document.querySelector('input').readOnly"), false);
		assert.equal(postRequests.length, count + 1);
		assert.ok(postRequests.at(-1).body.includes(`name="do"\r\n\r\n${button}`));
	}
	// Failed requests must clear the loader and leave the original icon available.
	await send("Network.emulateNetworkConditions", {offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0});
	await evaluate(`document.querySelector('form').requestSubmit(document.querySelector('button[value="link"]'))`);
	await waitFor("!document.querySelector('.flux-form-waiting, .flux-button-waiting')");
	assert.equal(await evaluate("document.querySelector('input').readOnly"), false);
	await evaluate("document.querySelector('input').focus()");
	assert.equal(await evaluate("document.activeElement === document.querySelector('input')"), true);
	assert.equal(await evaluate("getComputedStyle(document.querySelector('button[value=link]'), '::before').maskImage.includes('mail.svg')"), true);
	await send("Network.emulateNetworkConditions", {offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1});
	const countBeforeRetry = postRequests.length;
	await evaluate("window.previousForm = document.querySelector('form'); window.previousForm.requestSubmit(document.querySelector('button[value=link]'))");
	await waitFor("window.previousForm !== document.querySelector('form')");
	assert.equal(postRequests.length, countBeforeRetry + 1, "A failed submission must allow a retry");
	// Repeat code submissions to check controls still work after Flux replaces them.
	await navigate("security-check");
	for(const token of ["12345", "67890"]) {
		await evaluate(`document.querySelector('.security-code-digits input').focus()`);
		for(const digit of token) await send("Input.insertText", {text: digit});
		pendingPost = new Promise(resolve => { finishPost = resolve; });
		await evaluate("window.previousForm = document.querySelector('form'); window.previousForm.requestSubmit(document.querySelector('button[value=confirm]'))");
		await waitFor("!!document.querySelector('.flux-button-waiting')");
		await evaluate(`(() => { document.querySelector('.security-code-digits input').focus();
			const data = new DataTransfer(); data.setData('text/plain', '99999');
			document.activeElement.dispatchEvent(new ClipboardEvent('paste', {clipboardData: data, bubbles: true, cancelable: true})); })()`);
		await send("Input.dispatchKeyEvent", {type: "keyDown", key: "Backspace", code: "Backspace", windowsVirtualKeyCode: 8});
		await send("Input.dispatchKeyEvent", {type: "keyUp", key: "Backspace", code: "Backspace", windowsVirtualKeyCode: 8});
		assert.equal(await evaluate("new FormData(document.querySelector('form')).get('token')"), token);
		finishPost();
		finishPost = null;
		pendingPost = null;
		await waitFor("window.previousForm !== document.querySelector('form') && document.querySelectorAll('.security-code-digits input').length === 5");
		assert.ok(postRequests.at(-1).body.includes(`name="token"\r\n\r\n${token}`));
	}
	// Follow real redirects between different screens, including HTTP 403 and
	// a browser navigation to a client on another origin (without CORS).
	responseFixtures.set("/authenticate-step.html", {html: await readFile(join(temporary, "authenticate.html"), "utf8")});
	responseFixtures.set("/security-code-step.html", {html: await readFile(join(temporary, "security-check.html"), "utf8")});
	responseFixtures.set("/denied-step.html", {status: 403, html: await readFile(join(temporary, "access-denied.html"), "utf8")});
	responseFixtures.set("/client-return", {html: "<!doctype html><html><head><title>Client</title></head><body>CLIENT APPLICATION</body></html>"});
	const clientUrl = origin.replace("127.0.0.1", "localhost") + "/client-return";
	const successHtml = (await readFile(join(temporary, "success.html"), "utf8"))
		.replace('href="https://client.example.test/callback"', `href="${clientUrl}" data-client-redirect`);
	responseFixtures.set("/client-handoff.html", {html: successHtml});
	await send("Emulation.setTouchEmulationEnabled", {enabled: false});
	// Headless Chromium may report no pointing device. Emulate the desktop
	// capability query while retaining native media queries for themes/motion.
	const desktopMedia = await send("Page.addScriptToEvaluateOnNewDocument", {source: `
		const nativeMatchMedia = window.matchMedia.bind(window);
		window.matchMedia = query => {
			const result = nativeMatchMedia(query);
			if(query === '(hover: hover) and (pointer: fine)') {
				Object.defineProperty(result, 'matches', {value: true});
			}
			return result;
		};
	`});
	await navigate("index");
	assert.equal(await evaluate("matchMedia('(hover: hover) and (pointer: fine)').matches"), true);
	assert.equal(await evaluate("document.activeElement.type"), "email");
	nextRedirect = "/authenticate-step.html";
	await evaluate("document.activeElement.value = ''");
	await send("Input.insertText", {text: "test@example.test"});
	await send("Input.dispatchKeyEvent", {type: "keyDown", key: "Enter", code: "Enter", text: "\r", windowsVirtualKeyCode: 13});
	await send("Input.dispatchKeyEvent", {type: "keyUp", key: "Enter", code: "Enter", windowsVirtualKeyCode: 13});
	await waitFor("!!document.querySelector('input[type=password]') && location.pathname === '/authenticate-step.html'");
	assert.equal(await evaluate("document.activeElement.type"), "password");
	assert.equal(await evaluate("document.body.classList.contains('uri--login--authenticate')"), true);
	assert.ok(postRequests.at(-1).headers['x-authwave-flux'] === '1');
	nextRedirect = "/security-code-step.html";
	await send("Input.insertText", {text: "test-password-123"});
	await send("Input.dispatchKeyEvent", {type: "keyDown", key: "Enter", code: "Enter", text: "\r", windowsVirtualKeyCode: 13});
	await send("Input.dispatchKeyEvent", {type: "keyUp", key: "Enter", code: "Enter", windowsVirtualKeyCode: 13});
	await waitFor("!!document.querySelector('.security-code-digits input') && location.pathname === '/security-code-step.html'");
	assert.equal(await evaluate("document.activeElement === document.querySelector('.security-code-digits input')"), true);
	nextRedirect = "/denied-step.html";
	for(const digit of "12345") await send("Input.insertText", {text: digit});
	await evaluate("document.querySelector('form').requestSubmit(document.querySelector('button[value=confirm]'))");
	await waitFor("!!document.querySelector('button[value=switch-account]') && location.pathname === '/denied-step.html'");
	assert.equal(await evaluate(`document.body.textContent.includes("doesn't have access")`), true);
	assert.equal(await evaluate("document.querySelector('input[type=password]')"), null);
	nextRedirect = "/index.html";
	await evaluate("document.querySelector('form').requestSubmit(document.querySelector('button[value=switch-account]'))");
	await waitFor("!!document.querySelector('input[type=email]') && location.pathname === '/index.html'");
	assert.ok(postRequests.at(-1).body.includes('name="do"\r\n\r\nswitch-account'));
	nextRedirect = "/client-handoff.html";
	await evaluate("document.querySelector('input').value = 'test@example.test'; document.querySelector('form').requestSubmit(document.querySelector('button'))");
	await waitFor(`location.href === ${JSON.stringify(clientUrl)} && document.body.textContent === 'CLIENT APPLICATION'`);
	// Touch devices must not open the keyboard automatically on the next step.
	await send("Page.removeScriptToEvaluateOnNewDocument", {identifier: desktopMedia.identifier});
	await send("Emulation.setTouchEmulationEnabled", {enabled: true});
	await navigate("index");
	assert.equal(await evaluate("matchMedia('(hover: hover) and (pointer: fine)').matches"), false);
	assert.equal(await evaluate("document.activeElement === document.body"), true);
	nextRedirect = "/authenticate-step.html";
	await evaluate("document.querySelector('input').focus(); document.querySelector('input').value = 'test@example.test'; document.querySelector('form').requestSubmit(document.querySelector('button'))");
	await waitFor("!!document.querySelector('input[type=password]') && location.pathname === '/authenticate-step.html'");
	assert.equal(await evaluate("document.activeElement === document.querySelector('input[type=password]')"), false);
	await send("Emulation.setTouchEmulationEnabled", {enabled: false});
	await send("Emulation.setScriptExecutionDisabled", {value: true});
	await navigate("security-check");
	assert.equal(await evaluate("document.querySelector('[name=token]').type"), "text");
	assert.equal(await evaluate("document.querySelector('[name=token]').getClientRects().length > 0"), true);
	await evaluate("document.querySelector('[name=token]').value = '01234'");
	assert.equal(await evaluate("document.querySelector('form').checkValidity()"), true);
	assert.equal(await evaluate("new FormData(document.querySelector('form')).get('token')"), "01234");
	assert.deepEqual(failures, []);
	console.log("Passed: five responsive pages and assets; code entry and no-JavaScript submission; Flux loaders, field locking, duplicate prevention, failure retry and repeated code submissions.");
} finally {
	finishPost?.();
	socket?.close();
	if(browser && browser.exitCode === null) {
		browser.kill();
		await new Promise(resolve => browser.once("exit", resolve));
	}
	await new Promise(resolve => server ? server.close(resolve) : resolve());
	await rm(temporary, {recursive: true, force: true, maxRetries: 3, retryDelay: 100});
}
