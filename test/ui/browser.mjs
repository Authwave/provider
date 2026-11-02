// Run after building the assets: node test/ui/browser.mjs (Node 22+ and Chromium).
import assert from "node:assert/strict";
import {spawn, execFileSync} from "node:child_process";
import {mkdtemp, readFile, rm, mkdir, writeFile} from "node:fs/promises";
import {createServer} from "node:http";
import {tmpdir} from "node:os";
import {join, resolve} from "node:path";
import {setTimeout as delay} from "node:timers/promises";

const root = resolve(import.meta.dirname, "../..");
const temporary = await mkdtemp(join(tmpdir(), "authwave-ui-"));
let browser, socket, server;
try {
	execFileSync("php", ["test/ui/fixtures.php", temporary], {cwd: root});
	server = createServer(async (request, response) => {
		const path = new URL(request.url, "http://localhost").pathname;
		let file;
		if(/^\/(index|authenticate|security-check|success)\.html$/.test(path)) file = join(temporary, path);
		else if(path === "/style.css" || path === "/script.js") file = join(root, "www", path);
		else if(path.startsWith("/asset/") && !path.includes("..")) file = join(root, path);
		const types = {css: "text/css", js: "text/javascript", html: "text/html", svg: "image/svg+xml", woff2: "font/woff2"};
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
			&& !message.params.response.url.endsWith("favicon.ico")) failures.push(message.params.response.url);
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
	await send("Page.enable");
	await send("Runtime.enable");
	await send("Network.enable");
	await send("Emulation.setFocusEmulationEnabled", {enabled: true});
	for(const width of [390, 1280]) {
		await send("Emulation.setDeviceMetricsOverride", {width, height: 844, deviceScaleFactor: 1, mobile: width < 600});
		for(const mode of ["light", "dark"]) {
			await send("Emulation.setEmulatedMedia", {features: [{name: "prefers-color-scheme", value: mode}]});
			for(const page of ["index", "authenticate", "security-check", "success"]) {
				await navigate(page);
				assert.equal(await evaluate("document.documentElement.dataset.colorScheme"), mode);
				assert.equal(await evaluate("getComputedStyle(document.documentElement).getPropertyValue('--pal--theme').trim()"), mode === "light" ? "#123456" : "#abcdef");
				assert.equal(await evaluate("getComputedStyle(document.documentElement).backgroundColor"), mode === "light" ? "rgb(240, 241, 242)" : "rgb(16, 17, 18)");
				assert.equal(await evaluate("document.documentElement.scrollWidth <= innerWidth"), true, `${page} ${width}: overflow`);
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
			if(await evaluate(`document.documentElement.dataset.colorScheme === '${mode}' && new URL(document.querySelector('.logo').currentSrc).search === '?${mode}'`)) break;
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
		window.submitted = Object.fromEntries(new FormData(event.target, event.submitter));
	})`);
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
	await send("Emulation.setScriptExecutionDisabled", {value: true});
	await navigate("security-check");
	assert.equal(await evaluate("document.querySelector('[name=token]').type"), "text");
	assert.equal(await evaluate("document.querySelector('[name=token]').getClientRects().length > 0"), true);
	await evaluate("document.querySelector('[name=token]').value = '01234'");
	assert.equal(await evaluate("document.querySelector('form').checkValidity()"), true);
	assert.equal(await evaluate("new FormData(document.querySelector('form')).get('token')"), "01234");
	assert.deepEqual(failures, []);
	console.log("Passed: four pages at mobile/desktop widths in light/dark mode; assets; code entry, paste, confirm focus, Backspace, validation and no-JavaScript submission.");
} finally {
	socket?.close();
	if(browser && browser.exitCode === null) {
		browser.kill();
		await new Promise(resolve => browser.once("exit", resolve));
	}
	await new Promise(resolve => server ? server.close(resolve) : resolve());
	await rm(temporary, {recursive: true, force: true});
}
