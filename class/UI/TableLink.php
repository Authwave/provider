<?php
namespace Authwave\UI;

use GT\Dom\Element;
use InvalidArgumentException;

/** Enhances row cells with native links while preserving their content and controls. */
readonly class TableLink {
	public function __construct(Element $table) {
		if($table->tagName !== "table") {
			throw new InvalidArgumentException("TableLink requires a table element.");
		}

		foreach($table->querySelectorAll("tr") as $row) {
			foreach($row->querySelectorAll("a[data-table-link]") as $link) {
				$href = trim($link->getAttribute("href") ?? "");
				if($this->safeHref($href)) {
					$table->classList->add("table-link");
					foreach($row->children as $cell) {
						if(in_array($cell->tagName, ["td", "th"], true)) {
							$this->linkCell($cell, $link, $href);
						}
					}
				}
			}
		}
	}

	private function linkCell(Element $cell, Element $source, string $href):void {
		// Don't link a cell if it contains a control.
		$controls = "button, input, select, textarea, details, iframe, table, [contenteditable]";
		if($cell->querySelector($controls)) {
			return;
		}

		$existing = $cell->querySelectorAll("a");
		if(count($existing) > 0) {
			if(count($existing) === 1 && $existing[0] === $source) {
				$source->classList->add("table-link-anchor");
			}
			return;
		}

		$link = $cell->ownerDocument->createElement("a");
		$link->setAttribute("href", $href);
		$link->setAttribute("tabindex", "-1");
		$link->classList->add("table-link-anchor");

		while($cell->firstChild) {
			$link->appendChild($cell->firstChild);
		}

		$cell->appendChild($link);
	}

	private function safeHref(string $href):bool {
		if($href === "") {
			return false;
		}

		// Require an explicit scheme for external links.
		if(str_starts_with($href, "//")) {
			return false;
		}

		// Reject raw whitespace, control characters and backslashes.
		if(preg_match('/[\x00-\x20\x7f\\\\]/', $href)) {
			return false;
		}

		$parts = parse_url($href);

		if($parts === false) {
			return false;
		}

		if(isset($parts["user"]) || isset($parts["pass"])) {
			return false;
		}

		$scheme = strtolower($parts["scheme"] ?? "");

		if($scheme === "") {
			return true;
		}

		if($scheme !== "http" && $scheme !== "https") {
			return false;
		}

		return isset($parts["host"]);
	}
}
