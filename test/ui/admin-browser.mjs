// Isolated dashboard checks. No configured database, authentication or email services.
import assert from "node:assert/strict";
import {assertPageLayout} from "./page-layout.mjs";
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
const fluxRequests = [];
let fluxDelay = 0;
try {
	server = createServer(async (request, response) => {
		const url = new URL(request.url, "http://localhost");
		if(request.headers["x-authwave-flux"] === "1") fluxRequests.push(url.href);
		try {
			let file;
			if(url.pathname.startsWith("/admin/")) {
				const page = url.pathname.split("/").filter(Boolean)[1] || "index";
				if(!["index", "organisation", "security", "emails", "applications", "customisation", "users", "integrations", "billing", "logout"].includes(page)) throw new Error("Unknown page");
				const query = Object.fromEntries(url.searchParams);
				if(request.method === "POST") { let body = ""; for await(const part of request) body += part; Object.assign(query, Object.fromEntries(new URLSearchParams(body)), {__post: "yes"}); }
				file = join(temporary, "admin.html");
				execFileSync("php", ["test/ui/admin-fixtures.php", file, JSON.stringify(query), page, join(temporary, "demo-state.json")], {cwd: root, stdio: "ignore"});
				if(request.method === "POST") { const state=JSON.parse(await readFile(join(temporary,"demo-state.json"),"utf8")); response.writeHead(303,{Location:url.pathname+"?"+new URLSearchParams({organisation:state.organisation,application:state.application, deploymentScope:state.deployment || "all", ...(query.sender ? {sender:state.changedSender || query.sender} : {}), ...(query.template ? {template:query.template} : {})})}).end();return; }
			} else if(["/style.css", "/script.js", "/script.js.map"].includes(url.pathname)) file = join(root, "www", url.pathname);
			else if(url.pathname.startsWith("/asset/") && !url.pathname.includes("..")) file = join(root, url.pathname);
			const types = {html: "text/html", css: "text/css", js: "text/javascript", svg: "image/svg+xml", woff2: "font/woff2"};
			const content = await readFile(file || "");
			if(request.headers["x-authwave-flux"] === "1") await delay(fluxDelay);
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
	const navigate = async query => { await call("Page.navigate", {url: `${origin}/admin/${query || "?period=30d&application=admin-preview-no-logo"}`}); await until('document.readyState === "complete" && !!document.querySelector("admin-sidebar")'); };
	const checkBackgroundUpdate = async (action, ready, label, replacesMain = false) => {
		const timeOrigin = await evaluate('performance.timeOrigin');
		const requestCount = fluxRequests.length;
		await evaluate('window.fluxRegions = {body:document.body, main:document.querySelector("main"), sidebar:document.querySelector("admin-sidebar")}');
		await evaluate(action);
		await until(ready);
		await until('document.querySelector("admin-chart .chart svg") !== null && document.querySelector("admin-chart .chart").getBoundingClientRect().height > 0');
		assert.equal(await evaluate('performance.timeOrigin'), timeOrigin, `${label}: no document navigation`);
		assert.equal(fluxRequests.length, requestCount + 1, `${label}: one background request`);
		assert.equal(await evaluate('document.body === window.fluxRegions.body'), true, `${label}: body is retained`);
		assert.equal(await evaluate('document.querySelector("main") !== window.fluxRegions.main'), replacesMain, `${label}: main is only replaced on link navigation`);
		assert.equal(await evaluate('document.querySelector("admin-sidebar") !== window.fluxRegions.sidebar'), true, `${label}: sidebar is refreshed`);
		assert.equal(await evaluate('document.querySelectorAll("script").length'), 1, `${label}: shared bundle stays loaded once`);
	};
	const checkToolbarDropdowns = async () => {
		await evaluate('document.querySelector("admin-comparison summary").click()');
		await until('document.querySelector("admin-comparison details").open');
		await evaluate('document.querySelector("admin-comparison select").click()');
		assert.equal(await evaluate('document.querySelector("admin-comparison details").open'), true, "Clicking inside keeps the dropdown open");
		await evaluate('document.querySelector("admin-date-range details").open = true');
		await until('document.querySelector("admin-date-range details").open && !document.querySelector("admin-comparison details").open');
		await evaluate('document.querySelector("admin-date-range input[type=date]").focus()');
		await call("Input.dispatchKeyEvent", {type: "keyDown", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27});
		await call("Input.dispatchKeyEvent", {type: "keyUp", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27});
		assert.equal(await evaluate('document.querySelector("admin-date-range details").open'), false, "Escape closes the dropdown");
		assert.equal(await evaluate('document.activeElement === document.querySelector("admin-date-range summary")'), true, "Escape restores focus to its trigger");
		await evaluate('document.querySelector("admin-comparison summary").click()');
		await until('document.querySelector("admin-comparison details").open');
		await evaluate('document.querySelector("main").click()');
		assert.equal(await evaluate('document.querySelector("admin-comparison details").open'), false, "An outside click closes the dropdown");
	};
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
	assert.equal(await evaluate('document.querySelectorAll("script").length'), 1, "Only the shared bundle has a script tag");
	assert.equal(await evaluate('document.head.querySelector("script").getAttribute("src")'), "/script.js");
	await until('document.querySelectorAll("admin-chart svg").length === 1');
	await checkToolbarDropdowns();
	const avatarStyle = await evaluate(`(()=>{const svg=document.querySelector('.activity-table .avatar svg');return {colours:[...svg.querySelectorAll('[class*=avatar-colour]')].map(slice=>getComputedStyle(slice).fill),clip:getComputedStyle(svg.querySelector('.avatar-silhouette')).clipPath,shape:svg.dataset.shape,slicing:svg.dataset.slicing,viewBox:svg.viewBox.baseVal.width,hasNames:document.querySelector('main').textContent.includes('Sienna Hewitt'),sameAvatar:svg.outerHTML===document.querySelector('admin-new-users .avatar svg').outerHTML};})()`);
	assert.ok(new Set(avatarStyle.colours).size>=2, "Foreground and background have distinct palette colours");
	assert.notEqual(avatarStyle.clip, "none", "The slices are clipped to the selected silhouette");
	assert.ok(avatarStyle.shape && avatarStyle.slicing, "The hash selects a silhouette and slicing mode");
	assert.equal(avatarStyle.viewBox, 40, "Inline SVG retains its coordinate system");
	assert.equal(avatarStyle.hasNames, false, "Users are identified by email only");
	assert.equal(avatarStyle.sameAvatar, true, "The same email has the same artwork across lists");
	const themedAvatar = await evaluate(`(()=>{const svg=document.querySelector('.activity-table .avatar svg'),slice=svg.querySelector('[class*=avatar-colour]'),before=getComputedStyle(slice).fill;svg.style.setProperty('--theme-color-primary','#1274b8');const after=getComputedStyle(slice).fill;svg.style.removeProperty('--theme-color-primary');return {before,after};})()`);
	assert.notEqual(themedAvatar.before,themedAvatar.after,"Avatar colours follow a change in the primary theme colour");

	assert.equal(await evaluate('getComputedStyle(document.querySelector("main")).borderRadius'), "0px", "Admin main has square corners");
	for(const [group, title] of [["countries", "Country"], ["devices", "Device"], ["users", "User"]]) {
		await evaluate(`document.querySelector('admin-top-usage button[value="${group}"]').click()`);
		await until(`document.querySelector('admin-top-usage thead th')?.textContent === '${title}'`);
		assert.equal(await evaluate(`document.querySelectorAll('admin-top-usage button[aria-pressed="true"]').length`), 1, "Only one top usage tab is selected");
		assert.equal(await evaluate(`document.querySelector('admin-top-usage button[aria-pressed="true"]').value`), group);
	}
	assert.equal(await evaluate('document.querySelector("main admin-setup") === null'), true, "Setup is only displayed in the sidebar");
	assert.deepEqual(await evaluate('[...document.querySelectorAll(".metrics dt")].map(label=>label.textContent)'), ["Total usage", "New users", "Security codes sent", "Password changes", "Login success ratio", "Provider logins"]);
	const downwardTrend = await evaluate(`(()=>{const trend=document.querySelector('.metrics [data-trend="down"]');return {colour:getComputedStyle(trend).color,icon:getComputedStyle(trend,'::before').maskImage};})()`);
	assert.equal(downwardTrend.colour, "rgb(180, 35, 24)", "Downward trends use the accessible negative colour");
	assert.ok(downwardTrend.icon.includes("trending-down.svg"), "Downward trends use the Tabler icon");

	assert.equal(await evaluate('getComputedStyle(document.documentElement).backgroundColor'), "rgb(250, 250, 249)");
	assert.equal(await evaluate('getComputedStyle(document.querySelector("main")).backgroundColor'), "rgb(255, 255, 255)");
	assert.equal(await evaluate('getComputedStyle(document.documentElement).getPropertyValue("--theme-color-primary").trim()'), "#c328d1");
	assert.equal(await evaluate('document.querySelector(".side-navigation").getBoundingClientRect().height > 100'), true, "Closed desktop disclosure must show navigation");
	assert.equal(await evaluate('document.querySelector(".sidebar-menu").open'), false);
	assert.equal(await evaluate('getComputedStyle(document.querySelector(".quick-actions")).display'), "flex");
	assert.equal(await evaluate('document.documentElement.scrollWidth <= document.documentElement.clientWidth'), true);
	assert.equal(await evaluate('document.querySelectorAll(".activity-table tbody tr").length'), 7);
	assert.equal(await evaluate('document.querySelector(".dashboard-header").getBoundingClientRect().top'), 0, 'Desktop header starts at its sticky position');
	const segmentSelector = '.report-chart header .segmented-control button[aria-pressed="true"]';
	const segmentStyle = () => evaluate(`(()=>{const button=document.querySelector('${segmentSelector}'),style=getComputedStyle(button);return {shadow:style.boxShadow,background:style.backgroundColor,border:style.borderTopColor,borderWidth:style.borderTopWidth,zIndex:style.zIndex,groupShadow:getComputedStyle(button.closest(".segmented-control")).boxShadow};})()`);
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
	await evaluate('document.querySelector("aside").scrollTop = 0');
	await screenshot("admin-desktop-light");
	await evaluate('window.scrollTo(0, document.documentElement.scrollHeight)');
	const scrolledSidebar = await evaluate(`({scroll:window.scrollY,sidebarTop:document.querySelector('aside').getBoundingClientRect().top,position:getComputedStyle(document.querySelector('aside')).position})`);
	assert.ok(scrolledSidebar.scroll > 0, 'Dashboard is long enough to scroll');
	assert.equal(scrolledSidebar.position, 'fixed', 'Desktop sidebar is fixed to the viewport');
	assert.equal(scrolledSidebar.sidebarTop, 0, 'Desktop sidebar remains at the top while scrolling');
	assert.equal(await evaluate('document.querySelector(".dashboard-header").getBoundingClientRect().top'), 0, 'Main header remains at the top while scrolling');
	const stickyHeaderSpacing = await evaluate(`(()=>{
		const header=document.querySelector('.dashboard-header').getBoundingClientRect(),buttons=document.querySelectorAll('.quick-actions > *'),first=buttons[0].getBoundingClientRect(),second=buttons[1].getBoundingClientRect();
		return {above:first.top-header.top,between:second.left-first.right};
	})()`);
	assert.ok(Math.abs(stickyHeaderSpacing.above - stickyHeaderSpacing.between) <= 1, 'Sticky header top padding matches the gap between buttons');
	await evaluate('window.scrollTo(0, 0); document.querySelector("aside").scrollTop = 0');
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
		await assertPageLayout(evaluate, label, await evaluate("innerWidth >= 640"));
		const dimensions = await evaluate(`({page:document.documentElement.scrollWidth,viewport:document.documentElement.clientWidth,tables:[...document.querySelectorAll('.table-scroll')].map(el=>({width:el.scrollWidth,available:el.clientWidth}))})`);
		assert.ok(dimensions.page <= dimensions.viewport, `${label}: page overflow ${JSON.stringify(dimensions)}`);
		for(const table of dimensions.tables) assert.ok(table.width <= table.available, `${label}: table overflow ${JSON.stringify(table)}`);
	};
	for(const width of [280, 300, 320, 360, 375, 390, 414, 430, 639, 640, 768, 959, 960, 1024, 1280, 1440, 1920, 2560, 3840]) {
		await viewport(width);
		await delay(100);
		await assertNoHorizontalOverflow(`${width}px, menus closed`);
		const listCards = await evaluate(`[...document.querySelectorAll("main > .card-grid > * > section")].map(card=>{const rect=card.getBoundingClientRect();return {top:rect.top,left:rect.left};})`);
		assert.equal(listCards.length, 3, "Three dashboard lists");
		if(width < 640) assert.ok(listCards[1].top > listCards[0].top && listCards[2].top > listCards[1].top, `${width}px: lists stack on mobile`);
		if(width >= 1440) assert.ok(listCards.every(card=>Math.abs(card.top-listCards[0].top)<=1), `${width}px: lists share one row`);

		if([280, 390, 768].includes(width)) {
			for(const selector of ['admin-chart .chart']) {
				const point = await evaluate(`(()=>{const chart=document.querySelector('${selector}');chart.scrollIntoView({block:'center'});const rect=chart.getBoundingClientRect();return {x:rect.right-2,y:rect.bottom-40};})()`);
				await call('Input.dispatchMouseEvent', {type:'mouseMoved',...point});
				await until(`!!document.querySelector('${selector} > div[style*="pointer-events: none"]')?.checkVisibility({opacityProperty:true,visibilityProperty:true})`);
				await assertNoHorizontalOverflow(`${width}px, ${selector} tooltip open`);
				await call('Input.dispatchMouseEvent', {type:'mouseMoved',x:0,y:0});
				await delay(150);
			}
		}

		const reportLayout = await evaluate(`(()=>{const report=document.querySelector('section.report').getBoundingClientRect(),chart=document.querySelector('.report-chart').getBoundingClientRect(),metrics=document.querySelector('.metrics').getBoundingClientRect(),cards=[...document.querySelectorAll('.metrics > div')].map(item=>{const rect=item.getBoundingClientRect();return {left:rect.left,top:rect.top,width:rect.width};});return {reportWidth:report.width,chartWidth:chart.width,chartRight:chart.right,chartBottom:document.querySelector("admin-chart .chart").getBoundingClientRect().bottom,plotBottom:Math.max(...[...document.querySelectorAll("admin-chart svg path")].filter(path=>{const bounds=path.getBBox();return bounds.width>=chart.width-2 && bounds.height<1;}).map(path=>path.getBoundingClientRect().bottom)),metricsLeft:metrics.left,metricsTop:metrics.top,metricsBottom:metrics.bottom,metricsWidth:metrics.width,metricsGap:parseFloat(getComputedStyle(document.querySelector(".metrics")).columnGap),cards};})()`);
		assert.equal(reportLayout.cards.length, 6, `${width}px: six metrics`);
		assert.ok(reportLayout.chartWidth <= 1024, `${width}px: chart respects its 64rem maximum`);
		assert.ok(Math.abs(reportLayout.cards[0].top - reportLayout.cards[1].top) <= 1, `${width}px: metrics have at least two columns`);
		if(width < 640) {
			assert.ok(Math.abs(reportLayout.chartWidth - reportLayout.reportWidth) <= 1, `${width}px: mobile chart fills the report`);
			assert.ok(reportLayout.metricsTop >= reportLayout.chartBottom, `${width}px: mobile metrics sit below the chart`);
		}
		assert.ok(reportLayout.metricsWidth <= 1024, `${width}px: metrics container respects its 64rem maximum`);
		assert.ok(reportLayout.cards.every(card=>card.width<=288), `${width}px: metric cards respect their 18rem maximum`);
		for(let index=1;index<reportLayout.cards.length;index++) {
			const previous=reportLayout.cards[index-1],card=reportLayout.cards[index];
			if(Math.abs(card.top-previous.top)<=1) assert.ok(Math.abs(card.left-previous.left-previous.width-reportLayout.metricsGap)<=1, `${width}px: metric cards keep the configured gap`);
		}
		if(reportLayout.metricsWidth < 3*288+2*reportLayout.metricsGap) {
			assert.ok(reportLayout.cards[2].top > reportLayout.cards[0].top, `${width}px: metrics use a 2x3 grid`);
			assert.ok(Math.abs(reportLayout.cards[2].left - reportLayout.cards[0].left) <= 1, `${width}px: metric columns align`);
		}
		else {
			assert.ok(reportLayout.cards.slice(0,3).every(card=>Math.abs(card.top-reportLayout.cards[0].top)<=1), `${width}px: wide metrics use three columns`);
			assert.ok(reportLayout.cards[3].top>reportLayout.cards[0].top, `${width}px: wide metrics wrap onto a second row`);
		}
		if(width >= 1280) assert.ok(reportLayout.metricsLeft >= reportLayout.chartRight, `${width}px: metrics sit to the right of the chart ${JSON.stringify(reportLayout)}`);
		if(reportLayout.metricsLeft >= reportLayout.chartRight) assert.ok(Math.abs(reportLayout.metricsBottom-reportLayout.plotBottom)<=1, `${width}px: metrics and chart align at the bottom`);
		await evaluate('document.querySelector("admin-chart-data details").open = true');
		const expandedAlignment = await evaluate(`(()=>{const metrics=document.querySelector('.metrics').getBoundingClientRect(),chart=document.querySelector('admin-chart .chart').getBoundingClientRect();const plotBottom=Math.max(...[...document.querySelectorAll('admin-chart svg path')].filter(path=>{const bounds=path.getBBox();return bounds.width>=chart.width-2 && bounds.height<1;}).map(path=>path.getBoundingClientRect().bottom));return {metricsBottom:metrics.bottom,plotBottom};})()`);
		if(reportLayout.metricsLeft >= reportLayout.chartRight) assert.ok(Math.abs(expandedAlignment.metricsBottom-expandedAlignment.plotBottom)<=1, `${width}px: expanding chart data keeps metrics aligned with the plot`);
		await evaluate('document.querySelector("admin-chart-data details").open = false');

		await evaluate('window.scrollTo(0, document.documentElement.scrollHeight)');
		await delay(100);
		await assertNoHorizontalOverflow(`${width}px, header sticky`);
		await evaluate('window.scrollTo(0, 0); document.querySelector("aside").scrollTop = 0');
		const headerLayout = await evaluate(`(()=>{
			const rect = selector => document.querySelector(selector).getBoundingClientRect();
			const scope = rect('admin-sidebar .sidebar-header'), header = rect('.dashboard-header');
			return {scopeTop:scope.top,scopeBottom:scope.bottom,headerBottom:header.bottom,searchRight:rect('.search-field input').right,actionsLeft:rect('.quick-actions').left};
		})()`);
		assert.equal(headerLayout.scopeTop, 0, `${width}px: scope selector at top of sidebar`);
		if(width >= 960) assert.ok(Math.abs(headerLayout.scopeBottom - headerLayout.headerBottom) <= 1, `${width}px: sidebar/header alignment ${JSON.stringify(headerLayout)}`);
		if(width >= 640) assert.ok(headerLayout.searchRight < headerLayout.actionsLeft, `${width}px: search is left of quick actions`);
		const controlHeights = await evaluate(`[...document.querySelectorAll('.report-chart header .segmented-control, .report-chart header .segmented-control summary.control, .report-controls summary.control')].map(el=>el.getBoundingClientRect().height)`);
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

		for(const selector of ['.sidebar-menu', 'admin-comparison details', 'admin-date-range details', '.row-menu', 'tbody tr:last-child .row-menu', '.chart-data']) {
			await evaluate(`document.querySelector('${selector}').open = true`);
			await assertNoHorizontalOverflow(`${width}px, ${selector} open`);
			if(selector === 'admin-comparison details') {
				const placement = await evaluate(`(()=>{const menu=document.querySelector('${selector}'),trigger=menu.querySelector('summary').getBoundingClientRect(),content=menu.querySelector('.disclosure-content').getBoundingClientRect(),toolbar=menu.closest('.report-controls').getBoundingClientRect();return {left:content.left,right:content.right,top:content.top,triggerLeft:trigger.left,triggerBottom:trigger.bottom,toolbarLeft:toolbar.left,viewport:document.documentElement.clientWidth};})()`);
				assert.ok(placement.left >= 0 && placement.right <= placement.viewport, `${width}px: toolbar dropdown stays within the screen`);
				assert.ok(Math.abs(placement.left - (width >= 640 ? placement.triggerLeft : placement.toolbarLeft)) <= 1, `${width}px: toolbar dropdown anchors to the left`);
				assert.ok(placement.top >= placement.triggerBottom, `${width}px: toolbar dropdown opens below its button`);
			}
			if(selector.includes('row-menu')) {
				const bounds = await evaluate(`(()=>{const content=document.querySelector('${selector} .disclosure-content');const r=content.getBoundingClientRect();return {left:r.left,right:r.right,viewport:document.documentElement.clientWidth};})()`);
				assert.ok(bounds.left >= 0 && bounds.right <= bounds.viewport, `${width}px: row menu must fit the screen`);
			}
			await evaluate(`document.querySelector('${selector}').open = false`);
		}
	}
	await viewport(390);
	await navigate();
	assert.equal(await evaluate('document.querySelector("main admin-setup") === null'), true, "Mobile setup is only displayed in the sidebar");
	await delay(100);
	assert.equal(await evaluate('getComputedStyle(document.querySelector(".sidebar-menu"), "::details-content").contentVisibility'), "hidden");
	assert.equal(await evaluate('getComputedStyle(document.querySelector(".quick-actions")).display'), "none");
	assert.equal(await evaluate('document.documentElement.scrollWidth <= document.documentElement.clientWidth'), true);
	await screenshot("admin-mobile");
	await evaluate('document.querySelector(".sidebar-menu > summary").click()');
	assert.equal(await evaluate('document.querySelector(".side-navigation").getBoundingClientRect().height > 100'), true);
	await screenshot("admin-mobile-menu");
	await evaluate('document.querySelector(".sidebar-menu > summary").click()');
	await evaluate('document.querySelector("admin-comparison summary").click()');
	assert.equal(await evaluate('document.querySelector("admin-comparison details").open'), true);
	assert.equal(await evaluate('document.querySelector("admin-comparison .disclosure-content").getBoundingClientRect().left >= 0'), true);
	assert.equal(await evaluate('getComputedStyle(document.querySelector("admin-comparison button")).display'), "none", "Flux hides the comparison fallback button");
	await checkBackgroundUpdate('const select=document.querySelector("admin-comparison select[name=reportComparison]"); select.value="year"; select.dispatchEvent(new Event("change", {bubbles:true}))', 'location.search.includes("reportComparison=year") && JSON.parse(document.querySelector("admin-chart .chart").dataset.chart).comparisonTitle === "Previous year"', "Comparison autosave");
	assert.equal(await evaluate('JSON.parse(document.querySelector("admin-chart .chart").dataset.chart).comparisonTitle'), "Previous year");
	await checkToolbarDropdowns();
	await navigate();
	fluxDelay = 300;
	const periodTimeOrigin = await evaluate('performance.timeOrigin');
	await evaluate(`document.querySelector('button[value="7d"]').click()`);
	await until(`document.querySelector('form[aria-label="Reporting period"]').getAttribute("aria-busy") === "true" && document.querySelector('button[value="7d"]').classList.contains("flux-button-waiting")`);
	await until('location.search.includes("period=7d") && document.querySelector(".report-chart header p")?.textContent === "An overview of activity for the last 7 days"');
	assert.equal(await evaluate('performance.timeOrigin'), periodTimeOrigin, "Reporting period changes in the background");
	fluxDelay = 0;
	await navigate();
	await checkBackgroundUpdate('document.querySelector("a[data-icon=chevron-right]").click()', 'location.search.includes("page=2") && document.querySelector(".activity-table tbody th")?.textContent === "#26671"', "Activity pagination", true);
	for(const group of ["countries", "devices", "users"]) {
		await checkBackgroundUpdate(`document.querySelector('button[name="topUsage"][value="${group}"]').click()`, `document.querySelector('button[name="topUsage"][value="${group}"]').getAttribute('aria-pressed') === 'true'`, `Top usage ${group}`);
	}
	const visitPage = async (page, query = "organisation=example&application=all") => {
		await call("Page.navigate", {url: `${origin}/admin/${page}/?${query}`});
		await until(`document.readyState === "complete" && ${page === "organisation" ? "document.querySelector('main h1')?.textContent==='Organisation'" : `document.querySelector('[data-nav="${page}"][aria-current="page"]') !== null`}`);
	};
	for(const page of ["security", "emails", "applications", "customisation", "users", "integrations", "billing", "logout"]) {
		await visitPage(page);
		assert.equal(await evaluate('JSON.parse(document.querySelector("application-switcher select").value)[1]'), "admin-preview-no-logo", `${page}: default application scope`);
		for(const width of [280, 390, 768, 1440]) {
			await viewport(width);await delay(100);
			await assertNoHorizontalOverflow(`${page}, ${width}px`);
			await evaluate("document.querySelector('.sidebar-menu').open = true");
			await assertNoHorizontalOverflow(`${page}, ${width}px, navigation open`);
			await evaluate("document.querySelector('.sidebar-menu').open = false");
			const detailCount = await evaluate("document.querySelectorAll('main details').length");
			for(let i=0;i<detailCount;i++) {
				await evaluate(`document.querySelectorAll('main details')[${i}].open=true`);
				await assertNoHorizontalOverflow(`${page}, ${width}px, detail ${i} open`);
				await evaluate(`document.querySelectorAll('main details')[${i}].open=false`);
			}
		}
		await screenshot(`admin-${page}`);
	}
	await visitPage("users");
	assert.equal(await evaluate("document.querySelector('application-switcher option:not([disabled])').selected"), true);
	assert.equal(await evaluate("document.querySelector('main').textContent.includes('sienna@example.test')"), false, "User emails are censored by default");
	await visitPage("users", "organisation=example&application=all&reveal=yes&search=sienna");
	assert.equal(await evaluate("document.querySelectorAll('.record-table tbody tr').length"), 1, "Legacy aggregate scope resolves to one application");
	await visitPage("users", "organisation=example&application=operations&reveal=yes&search=sienna");
	assert.equal(await evaluate("document.querySelectorAll('.record-table tbody tr').length"), 1, "Application scope filters users");
	await evaluate("document.querySelector('[data-nav=security]').click()");
	await until("location.pathname==='/admin/security/' && document.querySelector('input[name=sessionTimeout]')!==null");
	assert.equal(await evaluate("new URL(location.href).searchParams.get('application')"), "operations", "Sidebar navigation preserves application scope");
	await evaluate("document.querySelector('input[name=sessionTimeout]').value='240';document.querySelector('input[name=sessionTimeout]').form.requestSubmit()");
	await until("document.readyState==='complete' && document.querySelector('input[name=sessionTimeout]')?.value==='240' && document.querySelector('[role=status]')?.textContent==='Demo settings saved.'");
	await visitPage("security", "organisation=example&application=operations");
	assert.equal(await evaluate("document.querySelector('input[name=sessionTimeout]').value"), "240", "Demo settings survive navigation");
	await visitPage("security", "organisation=example&application=all");
	assert.equal(await evaluate("document.querySelector('input[name=sessionTimeout]').value"), "1440", "Application settings do not change the organisation default");
	await visitPage("billing");
	await evaluate("document.querySelector('select[name=plan]').value='Scale';document.querySelector('select[name=plan]').form.requestSubmit()");
	await until("document.readyState==='complete' && document.querySelector('select[name=plan]')?.value==='Scale'");
	assert.equal(await evaluate("document.querySelector('.metric').textContent"), "Scale", "Changing the demo plan updates billing");
	await visitPage("users");
	assert.equal(await evaluate("document.querySelector('input[value=create-user]').form.querySelector('[name=email]').value"), "", "New user email field starts empty");
	await evaluate("const form=document.querySelector('input[value=create-user]').form;form.querySelector('[name=email]').value='ada@example.test';form.requestSubmit()");
	await until("document.readyState==='complete' && document.querySelector('[role=status]')?.textContent==='Demo user added. No invitation was sent.'");
	await visitPage("users", "organisation=example&application=all&search=ada&reveal=yes");
	assert.equal(await evaluate("document.querySelectorAll('.record-table tbody tr').length"), 1, "Adding a demo user updates the user list");
	await visitPage("emails", "organisation=example&application=all&sender=new");
	await evaluate("const form=document.querySelector('input[value=sender]').form;form.querySelector('[name=senderName]').value='New sender';form.querySelector('[name=senderEmail]').value='sender@example.test';form.querySelector('[name=replyTo]').value='reply@example.test';form.querySelector('[name=senderDomain]').value='example.test';form.requestSubmit()");
	await until("document.readyState==='complete' && document.querySelector('select[name=sender]')?.selectedOptions[0].textContent==='New sender'");
	assert.equal(await evaluate("document.querySelector('[name=senderEmail]').value"), "sender@example.test", "New demo sender is selectable with saved settings");
	await visitPage("applications");
	assert.equal(await evaluate("document.querySelectorAll('input[name=deployment]').length"), 2, "Application deployment settings are rendered");
	await evaluate("const form=document.querySelector('input[value=create-application]').form;form.querySelector('[name=name]').value='Analytics';form.requestSubmit()");
	await until("document.readyState==='complete' && [...document.querySelectorAll('h2')].some(el=>el.textContent==='Analytics')");
	assert.equal(await evaluate("[...document.querySelectorAll('application-switcher option')].some(el=>el.textContent==='Analytics')"), true, "New demo application appears in the scope selector");
	await visitPage("organisation");
	await evaluate("const form=document.querySelector('input[value=create-organisation]').form;form.querySelector('[name=name]').value='New team';form.requestSubmit()");
	await until("document.readyState==='complete' && document.querySelector('main').textContent.includes('New team')");
	await evaluate("document.querySelector('[data-nav=dashboard]').click()");
	await until("location.pathname==='/admin/' && document.readyState==='complete' && document.querySelector('main .report-controls')!==null");
	await until('document.querySelectorAll("admin-chart svg").length === 1');
	assert.equal(await evaluate('document.querySelectorAll("script").length'), 1, "Flux navigation keeps a single script tag");
	assert.equal(await evaluate("document.querySelector('main admin-setup')"), null, "New organisations use the sidebar setup only");
	assert.equal(await evaluate("document.querySelector('application-switcher select').selectedOptions[0].textContent"), 'No applications', "Empty organisations have no selected application");
	await viewport(390);
	await assertNoHorizontalOverflow("New organisation dashboard");
	await visitPage("users", "organisation=northstar&application=all");
	assert.equal(await evaluate("[...document.querySelectorAll('application-switcher option')].find(option=>option.textContent==='Customer portal')?.textContent"), "Customer portal", "Applications across organisations appear in the scope selector");
	await navigate();
	await evaluate('document.querySelector("admin-date-range summary").click(); const form=document.querySelector("admin-date-range form"); form.querySelector("[name=from]").value="2026-10-01"; form.querySelector("[name=to]").value="2026-10-05"; form.requestSubmit()');
	await until('document.querySelector(".report-chart header p")?.textContent === "An overview of activity between 1 Oct 2026 and 5 Oct 2026"');
	assert.equal(await evaluate('document.querySelector("admin-date-range summary").getAttribute("aria-current")'), "true", "Custom is selected for an applied date range");
	assert.equal(await evaluate(`document.querySelectorAll('button[name="period"][aria-pressed="true"]').length`), 0, "Custom dates deselect every preset");
	assert.equal(await evaluate('getComputedStyle(document.querySelector("admin-date-range summary")).color === getComputedStyle(document.querySelector("#overview-title")).color'), true, "Custom uses the selected segment text colour");
	await evaluate(`document.querySelector('button[value="7d"]').click()`);
	await until('document.querySelector(".report-chart header p")?.textContent === "An overview of activity for the last 7 days"');
	assert.equal(await evaluate('document.querySelector("admin-date-range summary").hasAttribute("aria-current")'), false, "Choosing a preset deselects Custom");
	assert.equal(await evaluate(`document.querySelector('button[value="7d"]').getAttribute("aria-pressed")`), "true");
	await navigate();
	await checkBackgroundUpdate('document.querySelector("admin-activity-filters input[type=checkbox][name=activitySuccess]").closest("label").click()', 'location.search.includes("activitySuccess=no") && document.querySelectorAll(".activity-table tbody tr").length===7 && !document.querySelector("admin-activity-filters form").classList.contains("flux-form-waiting")', "Activity filter");
	assert.equal(await evaluate('[...document.querySelectorAll(".activity-table tbody .status")].every(status=>["failed","abandoned"].includes(status.dataset.status))'), true, "Badge checkboxes combine activity statuses");
	await evaluate('document.querySelector("admin-activity-filters input[type=checkbox][name=activityAbandoned]").closest("label").click()');
	await until('location.search.includes("activityAbandoned=no") && document.querySelectorAll(".activity-table tbody tr").length===4');
	assert.equal(await evaluate('[...document.querySelectorAll(".activity-table tbody .status")].every(status=>status.dataset.status==="failed")'), true, "One selected badge filters to that status");
	await call("Input.dispatchKeyEvent", {type: "keyDown", key: "Tab", code: "Tab", windowsVirtualKeyCode: 9});
	await call("Input.dispatchKeyEvent", {type: "keyUp", key: "Tab", code: "Tab", windowsVirtualKeyCode: 9});
	await evaluate('document.querySelector("admin-activity-filters input[type=checkbox][name=activityFailed]").focus()');
	await until('getComputedStyle(document.querySelector("admin-activity-filters input[type=checkbox][name=activityFailed]").closest("label")).outlineColor !== "rgba(0, 0, 0, 0)"');
	await evaluate('document.querySelector("admin-activity-filters input[type=checkbox][name=activityFailed]").closest("label").click()');
	await until('location.search.includes("activityFailed=no") && document.querySelectorAll(".activity-table tbody tr").length===0');
	await call("Emulation.setScriptExecutionDisabled", {value: true});
	await navigate();
	await evaluate('document.querySelector(".sidebar-menu > summary").click(); document.querySelector(".chart-data summary").click()');
	assert.equal(await evaluate('document.querySelector(".sidebar-menu").open'), true);
	assert.equal(await evaluate('document.querySelector(".chart-data").open'), true);
	await assertNoHorizontalOverflow("No JavaScript");
	assert.equal(await evaluate('document.querySelector("admin-chart .chart").hidden'), true, "Without JavaScript, chart values remain available in the data table");
	await evaluate('document.querySelector("admin-comparison summary").click();document.querySelector("admin-comparison select").value="quarter";document.querySelector("admin-comparison button").click()');
	await until('document.readyState==="complete" && location.search.includes("reportComparison=quarter") && document.querySelector("admin-comparison select").value==="quarter"');
	await evaluate('document.querySelector("admin-top-usage button[value=countries]").click()');
	await until('document.readyState==="complete" && document.querySelector("admin-top-usage thead th").textContent==="Country"');
	await evaluate('document.querySelector("admin-activity-filters input[type=checkbox][name=activitySuccess]").checked=false;document.querySelector("admin-activity-filters input[type=checkbox][name=activityAbandoned]").checked=false;document.querySelector("admin-activity-filters button").click()');
	await until('document.readyState==="complete" && document.querySelectorAll(".activity-table tbody tr").length===4');
	assert.equal(await evaluate('document.querySelector("admin-activity-filters input[type=checkbox][name=activitySuccess]").checked'), false, "Badge filters submit without JavaScript");
	await evaluate(`document.querySelector('button[value="30d"]').click()`);
	await until('location.search.includes("period=30d") && document.querySelector(".report-chart header p")?.textContent === "An overview of activity for the last 30 days"');
	await evaluate(`const select=document.querySelector('application-switcher select');select.value=JSON.stringify(['example','operations','all']);select.form.querySelector('button').click()`);
	await until(`document.readyState==='complete' && document.querySelector('application-switcher select').selectedOptions[0].textContent==='Operations'`);
	await evaluate("document.querySelector('[data-nav=applications]').click()");
	await until("location.pathname==='/admin/applications/' && document.querySelector('input[name=deployment]')!==null");
	assert.equal(await evaluate("new URL(location.href).searchParams.get('application')"), "operations", "Application selection survives navigation without JavaScript");
	assert.equal(await evaluate("document.querySelectorAll('input[name=deployment]').length"), 1, "Selected application filters the deployment list");
	await visitPage("security", "organisation=example&application=all");
	await evaluate("document.querySelector('input[name=idleTimeout]').value='25';document.querySelector('input[name=idleTimeout]').form.requestSubmit()");
	await until("document.readyState==='complete' && document.querySelector('input[name=idleTimeout]')?.value==='25' && document.querySelector('[role=status]')?.textContent==='Demo settings saved.'");
	await visitPage("security", "organisation=example&application=all");
	assert.equal(await evaluate("document.querySelector('input[name=idleTimeout]').value"), "25", "Demo POST settings work without page JavaScript");
	assert.deepEqual(errors, []);
	console.log("Admin browser checks passed: all sections, organisation/application scope, demo POST settings, masked users, ECharts, responsive disclosures, sticky header and no-JavaScript controls.");
} finally {
	socket?.close(); browser?.kill(); server?.close(); await delay(100); await rm(temporary, {recursive: true, force: true});
}
