import * as echarts from "echarts/core";
import {LineChart} from "echarts/charts";
import {GridComponent, LegendComponent, TooltipComponent, AriaComponent} from "echarts/components";
import {SVGRenderer} from "echarts/renderers";

echarts.use([LineChart, GridComponent, LegendComponent, TooltipComponent, AriaComponent, SVGRenderer]);

const charts = new Map();
const darkMode = window.matchMedia("(prefers-color-scheme: dark)");
const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

function alignMetrics(element, chart) {
	const layout = element.closest(".report-layout");
	if(!layout) return;
	const metrics = layout.querySelector(":scope > .metrics");
	const container = element.querySelector(".chart");
	const plotBottom = chart.convertToPixel({yAxisIndex: 0}, 0);
	if(!Number.isFinite(plotBottom)) return;
	// Count charts start at zero, so its pixel position is the plot's lower edge.
	const labelSpace = Math.max(0, chart.getHeight() - plotBottom);
	layout.style.setProperty("--space--chart-labels", `${labelSpace}px`);
	layout.toggleAttribute("data-metrics-beside-chart", metrics.getBoundingClientRect().left >= container.getBoundingClientRect().right);
}

function draw(element, chart, payload) {
	const palette = getComputedStyle(document.documentElement);
	const colour = name => palette.getPropertyValue(name).trim();
	const primary = colour("--pal--theme");
	const secondary = colour("--pal--theme-secondary");
	chart.setOption({
		animation: !reducedMotion.matches,
		textStyle: {fontFamily: palette.fontFamily, color: colour("--pal--body--text")},
		aria: {enabled: true},
		tooltip: {trigger: "axis", confine: true, extraCssText: "max-width:100%;box-sizing:border-box;white-space:normal;overflow-wrap:anywhere;"},
		legend: {type: "scroll", top: 0, right: 0, icon: "circle", itemWidth: 8, itemHeight: 8, textStyle: {color: colour("--pal--body--text")}},
		grid: {left: 0, right: 0, top: 42, bottom: 28, outerBoundsMode: "none"},
		xAxis: {type: "category", data: payload.labels, boundaryGap: false, axisTick: {show: false}, axisLine: {lineStyle: {color: colour("--pal--panel--border")}}, axisLabel: {color: colour("--pal--body--text"), hideOverlap: true, alignMinLabel: "left", alignMaxLabel: "right"}},
		yAxis: {type: "value", min: 0, axisLabel: {show: false}, splitNumber: 4, splitLine: {lineStyle: {color: colour("--pal--panel--border"), opacity: .35}}},
		series: ([
			...(payload.comparison === "none" ? [] : [{name: payload.comparisonTitle, data: payload.previous, comparison: true}]),
			{name: "This period", data: payload.current},
		]).map(series => {
			const lineColour = series.comparison ? secondary : primary;
			return {name: series.name, type: "line", data: series.data, smooth: .25, showSymbol: false,
				lineStyle: {width: series.comparison ? 1 : 2, color: lineColour, opacity: series.comparison ? .45 : 1, type: series.comparison ? "dashed" : "solid"},
				itemStyle: {color: lineColour}, areaStyle: {color: lineColour, opacity: series.comparison ? .06 : .09}};
		}),
	}, {notMerge: true});
	alignMetrics(element, chart);
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
		const container = element.querySelector(".chart");
		// Keep the no-JavaScript fallback out of <noscript>: fetched HTML is
		// parsed with scripting disabled, which would activate its styles.
		container.hidden = false;
		const payload = JSON.parse(container.dataset.chart);
		const chart = echarts.init(container, null, {renderer: "svg"});
		const observer = new ResizeObserver(() => {
			chart.resize();
			alignMetrics(element, chart);
		});
		observer.observe(element);
		const layout = element.closest(".report-layout");
		if(layout) observer.observe(layout);
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
