// Isolated dashboard checks. No configured database, authentication or email services.
import assert from "node:assert/strict";
import {spawn, execFileSync} from "node:child_process";
import {mkdtemp, readFile, writeFile, rm, mkdir} from "node:fs/promises";
import {createServer} from "node:http";
import {join, resolve} from "node:path";
import {tmpdir} from "node:os";
import {setTimeout as delay} from "node:timers/promises";

const root = resolve(import.meta.dirname, "../..");
const temporary = await mkdtemp(join(tmpdir(), "authwave-admin-"));
let browser, socket, server;
const errors = [];
try {
	server = createServer(async (request, response) => {
		const url = new URL(request.url, "http://localhost");
		try {
			let file;
			if(url.pathname === "/admin/") {
				file = join(temporary, "admin.html");
				execFileSync("php", ["test/ui/admin-fixtures.php", file, JSON.stringify(Object.fromEntries(url.searchParams))], {cwd: root, stdio: "ignore"});
			} else if(["/style.css", "/script.js", "/script.js.map", "/admin.js", "/admin.js.map"].includes(url.pathname)) file = join(root, "www", url.pathname);
			else if(url.pathname.startsWith("/asset/") && !url.pathname.includes("..")) file = join(root, url.pathname);
			const types = {html: "text/html", css: "text/css", js: "text/javascript", svg: "image/svg+xml", woff2: "font/woff2"};
			const content = await readFile(file || "");
			response.writeHead(200, {"Content-Type": types[file?.split(".").pop()] || "application/octet-stream"}).end(content);
		} catch { response.writeHead(404).end(); }
	});
	await new Promise(resolve => server.listen(0, "127.0.0.1", resolve));
	const origin = `http://127.0.0.1:${server.address().port}`;
	browser = spawn(process.env.CHROMIUM || "chromium", ["--headless", "--no-sandbox", "--disable-gpu", "--no-first-run", "--remote-debugging-port=0", `--user-data-dir=${temporary}/profile`], {stdio: "ignore"});
	let port;
	for(let attempt = 0; attempt < 100; attempt++) {
		try { port = (await readFile(`${temporary}/profile/DevToolsActivePort`, "utf8")).split("\n")[0]; break; } catch { await delay(100); }
	}
	assert.ok(port, "Chromium must start");
	const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
	socket = new WebSocket(targets.find(target => target.type === "page").webSocketDebuggerUrl);
	await new Promise(resolve => socket.addEventListener("open", resolve, {once: true}));
	let id = 0;
	const pending = new Map();
	socket.addEventListener("message", event => {
		const message = JSON.parse(event.data);
		if(message.id) { const entry = pending.get(message.id); pending.delete(message.id); message.error ? entry.reject(new Error(JSON.stringify(message.error))) : entry.resolve(message.result); }
		if(message.method === "Runtime.exceptionThrown") errors.push(message.params.exceptionDetails.text);
	});
	const call = (method, params = {}) => new Promise((resolve, reject) => { pending.set(++id, {resolve, reject}); socket.send(JSON.stringify({id, method, params})); });
	const evaluate = async expression => {
		const result = await call("Runtime.evaluate", {expression, returnByValue: true, awaitPromise: true});
		if(result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
		return result.result.value;
	};
	const until = async expression => { for(let i = 0; i < 100; i++) { if(await evaluate(expression)) return; await delay(50); } throw new Error(`Timed out: ${expression}`); };
	const navigate = async query => { await call("Page.navigate", {url: `${origin}/admin/${query || ""}`}); await until('document.readyState === "complete" && !!document.querySelector("admin-sidebar")'); };
	const viewport = (width, height = 1000) => call("Emulation.setDeviceMetricsOverride", {width, height, deviceScaleFactor: 1, mobile: width < 640});
	const screenshot = async name => {
		if(!process.env.UI_SCREENSHOT_DIR) return;
		await mkdir(process.env.UI_SCREENSHOT_DIR, {recursive: true});
		const {cssContentSize: size} = await call("Page.getLayoutMetrics");
		const {data} = await call("Page.captureScreenshot", {captureBeyondViewport: true, clip: {x: 0, y: 0, width: size.width, height: size.height, scale: 1}});
		await writeFile(join(process.env.UI_SCREENSHOT_DIR, `${name}.png`), Buffer.from(data, "base64"));
	};
	await call("Page.enable"); await call("Runtime.enable");
	await viewport(1440);
	await navigate();
	await until('!!document.querySelector("admin-chart svg")');
	assert.equal(await evaluate('getComputedStyle(document.documentElement).backgroundColor'), "rgb(250, 250, 249)");
	assert.equal(await evaluate('getComputedStyle(document.querySelector("main")).backgroundColor'), "rgb(255, 255, 255)");
	assert.equal(await evaluate('getComputedStyle(document.documentElement).getPropertyValue("--pal--theme").trim()'), "#c328d1");
	assert.equal(await evaluate('document.querySelector(".side-navigation").getBoundingClientRect().height > 100'), true, "Closed desktop disclosure must show navigation");
	assert.equal(await evaluate('document.querySelector(".sidebar-menu").open'), false);
	assert.equal(await evaluate('getComputedStyle(document.querySelector(".quick-actions")).display'), "flex");
	assert.equal(await evaluate('document.documentElement.scrollWidth <= document.documentElement.clientWidth'), true);
	assert.equal(await evaluate('document.querySelectorAll(".activity-table tbody tr").length'), 7);
	assert.equal(await evaluate('document.querySelector(".dashboard-header").getBoundingClientRect().top'), 0, 'Desktop header starts at its sticky position');
	const segmentSelector = '.dashboard-controls .segmented-control > button[aria-pressed="true"]';
	const segmentStyle = () => evaluate(`(()=>{const button=document.querySelector('${segmentSelector}'),style=getComputedStyle(button);return {shadow:style.boxShadow,background:style.backgroundColor,border:style.borderTopColor,borderWidth:style.borderTopWidth,zIndex:style.zIndex,groupShadow:getComputedStyle(button.parentElement).boxShadow};})()`);
	const restingSegment = await segmentStyle();
	assert.equal(restingSegment.shadow, 'none', 'Segments have no individual shadows');
	assert.notEqual(restingSegment.groupShadow, 'none', 'The group carries the outer shadow');
	await call('DOM.enable'); await call('CSS.enable');
	const {root: domRoot} = await call('DOM.getDocument');
	const {nodeId: segmentNode} = await call('DOM.querySelector', {nodeId: domRoot.nodeId, selector: segmentSelector});
	await call('CSS.forcePseudoState', {nodeId: segmentNode, forcedPseudoClasses: ['hover']});
	await delay(250);
	assert.equal((await segmentStyle()).shadow, 'none', 'Hovered segments have no individual shadows');
	const hoveredSegment = await segmentStyle();
	assert.notEqual(hoveredSegment.border, restingSegment.border, 'Hover changes the segment border colour');
	assert.notEqual(hoveredSegment.borderWidth, '0px', 'Segments have visible borders');
	assert.equal(hoveredSegment.zIndex, '1', 'Hover raises the segment above its neighbours');
	await call('CSS.forcePseudoState', {nodeId: segmentNode, forcedPseudoClasses: ['hover', 'active']});
	await delay(250);
	const activeSegment = await segmentStyle();
	assert.equal(activeSegment.shadow, 'none', 'Pressed segments have no individual shadows');
	assert.notEqual(activeSegment.background, restingSegment.background, 'Selected segments still show the pressed background');
	await call('CSS.forcePseudoState', {nodeId: segmentNode, forcedPseudoClasses: []});
	await call('Emulation.setFocusEmulationEnabled', {enabled: true});
	await evaluate(`document.querySelector('${segmentSelector}').focus()`);
	assert.equal(await evaluate(`getComputedStyle(document.querySelector('${segmentSelector}')).zIndex`), '2', 'Focus raises the segment above hovered neighbours');
	await evaluate(`document.querySelector('${segmentSelector}').blur()`);
	const navigationFocus = await evaluate(`(async()=>{
		const outline = el => {const style=getComputedStyle(el);return [style.outlineColor,style.outlineWidth,style.outlineStyle,style.outlineOffset];};
		const search=document.querySelector('.search-field input');search.focus();
		await new Promise(resolve=>setTimeout(resolve,150));const expected=outline(search),items=[];
		for(const item of document.querySelectorAll('.side-navigation a, .side-navigation button')) {
			item.focus();await new Promise(resolve=>setTimeout(resolve,150));items.push(outline(item));
		}
		document.activeElement.blur();return {expected,items};
	})()`);
	for(const outline of navigationFocus.items) assert.deepEqual(outline, navigationFocus.expected, 'Every sidebar item has the text input focus outline');
	await screenshot("admin-desktop-light");
	await evaluate('window.scrollTo(0, document.documentElement.scrollHeight)');
	const scrolledSidebar = await evaluate(`({scroll:window.scrollY,sidebarTop:document.querySelector('aside').getBoundingClientRect().top,position:getComputedStyle(document.querySelector('aside')).position})`);
	assert.ok(scrolledSidebar.scroll > 0, 'Dashboard is long enough to scroll');
	assert.equal(scrolledSidebar.position, 'fixed', 'Desktop sidebar is fixed to the viewport');
	assert.equal(scrolledSidebar.sidebarTop, 0, 'Desktop sidebar remains at the top while scrolling');
	assert.equal(await evaluate('document.querySelector(".dashboard-header").getBoundingClientRect().top'), 0, 'Main header remains at the top while scrolling');
	const stickyHeaderSpacing = await evaluate(`(()=>{
		const header=document.querySelector('.dashboard-header').getBoundingClientRect(),buttons=document.querySelectorAll('.quick-actions button'),first=buttons[0].getBoundingClientRect(),second=buttons[1].getBoundingClientRect();
		return {above:first.top-header.top,between:second.left-first.right};
	})()`);
	assert.ok(Math.abs(stickyHeaderSpacing.above - stickyHeaderSpacing.between) <= 1, 'Sticky header top padding matches the gap between buttons');
	await evaluate('window.scrollTo(0, 0)');
	assert.equal(await evaluate('document.querySelector("application-switcher select").selectedOptions[0].textContent'), 'TrackShift');
	await call("Emulation.setEmulatedMedia", {features: [{name: "prefers-color-scheme", value: "dark"}]});
	await until('document.documentElement.dataset.colorScheme === "dark"');
	await delay(150);
	assert.equal(await evaluate('getComputedStyle(document.documentElement).backgroundColor'), "rgb(69, 63, 58)");
	assert.equal(await evaluate('getComputedStyle(document.querySelector("main")).backgroundColor'), "rgb(43, 37, 36)");
	assert.equal(await evaluate('getComputedStyle(document.querySelector("admin-sidebar")).backgroundColor'), "rgb(43, 37, 36)");
	await screenshot("admin-desktop-dark");
	await call("Emulation.setEmulatedMedia", {features: [{name: "prefers-color-scheme", value: "light"}]});
	// Use clientWidth: innerWidth includes a desktop browser's vertical scrollbar.
	const assertNoHorizontalOverflow = async label => {
		const dimensions = await evaluate(`({page:document.documentElement.scrollWidth,viewport:document.documentElement.clientWidth,tables:[...document.querySelectorAll('.table-scroll')].map(el=>({width:el.scrollWidth,available:el.clientWidth}))})`);
		assert.ok(dimensions.page <= dimensions.viewport, `${label}: page overflow ${JSON.stringify(dimensions)}`);
		for(const table of dimensions.tables) assert.ok(table.width <= table.available, `${label}: table overflow ${JSON.stringify(table)}`);
	};
	for(const width of [280, 300, 320, 360, 375, 390, 414, 430, 639, 640, 768, 959, 960, 1024, 1280, 1440]) {
		await viewport(width);
		await delay(100);
		await assertNoHorizontalOverflow(`${width}px, menus closed`);
		await evaluate('window.scrollTo(0, document.documentElement.scrollHeight)');
		await delay(100);
		await assertNoHorizontalOverflow(`${width}px, header sticky`);
		await evaluate('window.scrollTo(0, 0)');
		const headerLayout = await evaluate(`(()=>{
			const rect = selector => document.querySelector(selector).getBoundingClientRect();
			const logo = rect('admin-sidebar img'), sidebar = rect('admin-sidebar'), header = rect('.dashboard-header');
			return {logoCentre:logo.left+logo.width/2,sidebarCentre:sidebar.left+sidebar.width/2,brandBottom:rect('admin-sidebar .brand').bottom,headerBottom:header.bottom,searchRight:rect('.search-field input').right,actionsLeft:rect('.quick-actions').left};
		})()`);
		assert.ok(Math.abs(headerLayout.logoCentre - headerLayout.sidebarCentre) <= 1, `${width}px: centred logo`);
		if(width >= 960) assert.ok(Math.abs(headerLayout.brandBottom - headerLayout.headerBottom) <= 1, `${width}px: sidebar/header alignment ${JSON.stringify(headerLayout)}`);
		if(width >= 640) assert.ok(headerLayout.searchRight < headerLayout.actionsLeft, `${width}px: search is left of quick actions`);
		const controlHeights = await evaluate(`[...document.querySelectorAll('.dashboard-controls > .segmented-control, .dashboard-controls summary.control')].map(el=>el.getBoundingClientRect().height)`);
		assert.ok(controlHeights.every(height => Math.abs(height - controlHeights[0]) <= 1), `${width}px: equal toolbar control heights ${JSON.stringify(controlHeights)}`);
		const rowDisclosure = await evaluate(`(()=>{
			const cell=document.querySelector('tbody [data-priority="disclosure"]'),summary=cell.querySelector('summary'),secondary=document.querySelector('tbody [data-priority="secondary"]');
			const c=cell.getBoundingClientRect(),s=summary.getBoundingClientRect(),style=getComputedStyle(summary);
			return {columnsHidden:getComputedStyle(secondary).display==='none',visible:getComputedStyle(cell).display!=='none',centreOffset:s.left+s.width/2-c.left-c.width/2,display:style.display,placeItems:style.placeItems};
		})()`);
		assert.equal(rowDisclosure.visible, rowDisclosure.columnsHidden, `${width}px: row disclosure appears only for hidden columns`);
		if(rowDisclosure.visible) {
			assert.ok(Math.abs(rowDisclosure.centreOffset) <= 1, `${width}px: centred row disclosure`);
			assert.equal(rowDisclosure.display, 'inline-grid');
			assert.equal(rowDisclosure.placeItems, 'center');
		}

		for(const selector of ['.sidebar-menu', 'admin-filters details', 'admin-date-range details', 'admin-view details', '.row-menu', 'tbody tr:last-child .row-menu', '.chart-data']) {
			await evaluate(`document.querySelector('${selector}').open = true`);
			await assertNoHorizontalOverflow(`${width}px, ${selector} open`);
			if(selector.includes('row-menu')) {
				const bounds = await evaluate(`(()=>{const content=document.querySelector('${selector} .disclosure-content');const r=content.getBoundingClientRect();return {left:r.left,right:r.right,viewport:document.documentElement.clientWidth};})()`);
				assert.ok(bounds.left >= 0 && bounds.right <= bounds.viewport, `${width}px: row menu must fit the screen`);
			}
			await evaluate(`document.querySelector('${selector}').open = false`);
		}
	}
	await viewport(390);
	await delay(100);
	assert.equal(await evaluate('getComputedStyle(document.querySelector(".sidebar-menu"), "::details-content").contentVisibility'), "hidden");
	assert.equal(await evaluate('getComputedStyle(document.querySelector(".quick-actions")).display'), "none");
	assert.equal(await evaluate('document.documentElement.scrollWidth <= document.documentElement.clientWidth'), true);
	await screenshot("admin-mobile");
	await evaluate('document.querySelector(".sidebar-menu > summary").click()');
	assert.equal(await evaluate('document.querySelector(".side-navigation").getBoundingClientRect().height > 100'), true);
	await screenshot("admin-mobile-menu");
	await evaluate('document.querySelector(".sidebar-menu > summary").click()');
	await evaluate('document.querySelector("admin-filters summary").click()');
	assert.equal(await evaluate('document.querySelector("admin-filters details").open'), true);
	assert.equal(await evaluate('document.querySelector("admin-filters .disclosure-content").getBoundingClientRect().left >= 0'), true);
	await evaluate('document.querySelector("admin-filters select[name=status]").value = "failed"; document.querySelector("admin-filters form").requestSubmit()');
	await until('location.search.includes("status=failed") && document.readyState === "complete"');
	assert.equal(await evaluate('document.querySelectorAll(".activity-table tbody tr").length'), 4);
	assert.equal(await evaluate('document.querySelector(".activity-table .status").textContent'), "Failed");
	await navigate();
	await evaluate(`document.querySelector('button[value="7d"]').click()`);
	await until('location.search.includes("period=7d") && document.querySelector("#overview-title")?.textContent === "This week"');
	await navigate();
	await evaluate('document.querySelector("a[data-icon=chevron-right]").click()');
	await until('location.search.includes("page=2") && document.querySelector(".activity-table tbody th")?.textContent === "#26671"');
	await call("Emulation.setScriptExecutionDisabled", {value: true});
	await navigate();
	await evaluate('document.querySelector(".sidebar-menu > summary").click(); document.querySelector(".chart-data summary").click()');
	assert.equal(await evaluate('document.querySelector(".sidebar-menu").open'), true);
	assert.equal(await evaluate('document.querySelector(".chart-data").open'), true);
	await assertNoHorizontalOverflow("No JavaScript");
	await evaluate(`document.querySelector('button[value="30d"]').click()`);
	await until('location.search.includes("period=30d") && document.querySelector("#overview-title")?.textContent === "This month"');
	assert.deepEqual(errors, []);
	console.log("Admin browser checks passed: desktop/mobile disclosures, dark theme, ECharts, GET filters, periods, pagination and no-JavaScript controls.");
} finally {
	socket?.close(); browser?.kill(); server?.close(); await delay(100); await rm(temporary, {recursive: true, force: true});
}
