import * as echarts from "echarts";

const charts = new Map();
const darkMode = window.matchMedia("(prefers-color-scheme: dark)");
const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

function draw(element, chart, payload) {
	const palette = getComputedStyle(document.documentElement);
	const colour = name => palette.getPropertyValue(name).trim();
	const primary = colour("--pal--theme");
	const secondary = colour("--pal--theme-secondary");
	chart.setOption({
		animation: !reducedMotion.matches,
		textStyle: {fontFamily: palette.fontFamily, color: colour("--pal--body--text")},
		aria: {enabled: true},
		tooltip: {trigger: "axis"},
		legend: {top: 0, right: 0, icon: "circle", itemWidth: 8, itemHeight: 8, textStyle: {color: colour("--pal--body--text")}},
		grid: {left: 0, right: 0, top: 42, bottom: 28, outerBoundsMode: "none"},
		xAxis: {type: "category", data: payload.labels, boundaryGap: false, axisTick: {show: false}, axisLine: {lineStyle: {color: colour("--pal--panel--border")}}, axisLabel: {color: colour("--pal--body--text"), hideOverlap: true, alignMinLabel: "left", alignMaxLabel: "right"}},
		yAxis: {type: "value", axisLabel: {show: false}, splitNumber: 4, splitLine: {lineStyle: {color: colour("--pal--panel--border"), opacity: .35}}},
		series: [
			{name: "Previous period", type: "line", data: payload.previous, smooth: .25, showSymbol: false, lineStyle: {width: 1, color: secondary, opacity: .45}, itemStyle: {color: secondary}, areaStyle: {color: secondary, opacity: .06}},
			{name: "This period", type: "line", data: payload.current, smooth: .25, showSymbol: false, lineStyle: {width: 2, color: primary}, itemStyle: {color: primary}, areaStyle: {color: primary, opacity: .09}},
		],
	});
}

function initialise() {
	for(const [element, entry] of charts) {
		if(!element.isConnected) {
			entry.observer.disconnect();
			entry.chart.dispose();
			charts.delete(element);
		}
	}
	for(const element of document.querySelectorAll("admin-chart")) {
		if(charts.has(element)) continue;
		const payload = JSON.parse(element.querySelector('script[type="application/json"]').textContent);
		const chart = echarts.init(element.querySelector(".chart"), null, {renderer: "svg"});
		const observer = new ResizeObserver(() => chart.resize());
		observer.observe(element);
		charts.set(element, {chart, observer, payload});
		draw(element, chart, payload);
	}
}

function redraw() {
	// The shared colour-scheme listener updates theme variables first.
	requestAnimationFrame(() => {
		for(const [element, {chart, payload}] of charts) draw(element, chart, payload);
	});
}

initialise();
document.addEventListener("flux:after-render", initialise);
darkMode.addEventListener("change", redraw);
reducedMotion.addEventListener("change", redraw);
