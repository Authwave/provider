import assert from "node:assert/strict";

export async function assertPageLayout(evaluate, label, desktop) {
	const layout = await evaluate(`(()=>{
		const viewport=document.documentElement.clientWidth;
		const overflowing=[...document.body.querySelectorAll('*')].filter(element=>{
			if(!(element instanceof HTMLElement) || !element.checkVisibility({opacityProperty:true,visibilityProperty:true})) return false;
			const rect=element.getBoundingClientRect();
			return rect.width>1 && (rect.left < -1 || rect.right > viewport+1);
		}).map(element=>({tag:element.tagName,class:element.className,left:element.getBoundingClientRect().left,right:element.getBoundingClientRect().right}));
		const footer=document.querySelector('.site-footer'),links=[...footer.querySelectorAll('a')].map(link=>{const rect=link.getBoundingClientRect();return {left:rect.left,right:rect.right,top:rect.top};});
		const y=window.scrollY;
		window.scrollTo(1000,y);
		const scrollX=window.scrollX;
		window.scrollTo(0,y);
		return {viewport,page:document.documentElement.scrollWidth,overflowing,scrollX,links,footerRight:footer.getBoundingClientRect().right-parseFloat(getComputedStyle(footer).paddingRight)};
	})()`);
	assert.ok(layout.page <= layout.viewport, `${label}: document is wider than the viewport ${JSON.stringify(layout)}`);
	assert.deepEqual(layout.overflowing, [], `${label}: overflowing visible content`);
	assert.equal(layout.scrollX, 0, `${label}: the page must not scroll horizontally`);
	assert.equal(layout.links.length, 3, `${label}: three footer links`);
	if(layout.links.every(link=>Math.abs(link.top-layout.links[0].top)<=1)) {
		assert.ok(Math.abs((layout.links[1].left-layout.links[0].right)-(layout.links[2].left-layout.links[1].right))<=1, `${label}: equal footer link spacing`);
	}
	if(desktop) assert.ok(Math.abs(layout.links[2].right-layout.footerRight)<=1, `${label}: footer links align to the right`);
}
