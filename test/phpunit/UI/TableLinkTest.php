<?php

namespace Authwave\Test\UI;

use Authwave\UI\TableLink;
use GT\Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TableLinkTest extends TestCase {
	public function testConstruct_everyCellUsesItsRowHrefAndPreservesItsNodes():void {
		$document = new HTMLDocument('<table><thead><tr><th>Name</th></tr></thead><tbody><tr><td><a data-table-link href="/applications/one/">Name</a></td><td><code>ID</code> tail</td><td></td><th scope="row">Row</th></tr><tr><td><a data-table-link href="/applications/two/">Second</a></td></tr></tbody></table>');
		$code = $document->querySelector('code');
		$table = $document->querySelector('table');
		$sut = new TableLink($table);
		self::assertNull($table->querySelector('thead a'));
		self::assertCount(4, $table->querySelectorAll('tbody tr:first-child > * > a'));
		foreach($table->querySelectorAll('tbody tr:first-child > * > a') as $link) {
			self::assertSame('/applications/one/', $link->getAttribute('href'));
		}
		self::assertSame($code, $table->querySelector('td > a > code'));
		self::assertSame('ID tail', $code->parentElement->textContent);
		self::assertSame('/applications/two/', $table->querySelector('tbody tr:last-child a')->getAttribute('href'));
		self::assertNull($table->querySelector('a a'));
		foreach($table->querySelectorAll('a:not([data-table-link])') as $extra) {
			self::assertSame('-1', $extra->getAttribute('tabindex'));
		}
		self::assertFalse($table->querySelector('a[data-table-link]')->hasAttribute('tabindex'));
	}

	public function testConstruct_alreadyEnhancedTableRemainsUnchanged():void {
		$document = new HTMLDocument('<table class="table-link"><tr><td><a data-table-link class="table-link-anchor" href="/app/">Name</a></td><td><a class="table-link-anchor" href="/app/" tabindex="-1">ID</a></td></tr></table>');
		$table = $document->querySelector("table");
		$html = $table->outerHTML;

		$sut = new TableLink($table);

		self::assertSame($html, $table->outerHTML);
	}

	#[DataProvider('badHrefs')]
	public function testConstruct_missingAndUnsafeHrefsLeaveCellsUntouched(string $attribute):void {
		$document = new HTMLDocument('<table><tr><td><a data-table-link ' . $attribute . '>Original</a></td><td>Value</td></tr></table>');
		$table = $document->querySelector('table');
		$html = $table->outerHTML;
		$sut = new TableLink($table);
		self::assertSame($html, $table->outerHTML);
	}

	public static function badHrefs():iterable {
		foreach(['', 'href=""', 'href=" "',
			'href="javascript:alert(1)"', 'href="data:text/html,x"',
			'href="//external.test/"', 'href="https://user:pass@host.test/"',
			'href="/bad&#10;path"', 'href="/bad\\path"'] as $attribute) yield [$attribute];
	}

	#[DataProvider('validHrefs')]
	public function testConstruct_safeRelativeAndHttpTargetsArePreserved(string $href):void {
		$document = new HTMLDocument('<table><tr><td><a data-table-link>Source</a></td><td>Value</td></tr></table>');
		$document->querySelector('a')->setAttribute('href', $href);
		$sut = new TableLink($document->querySelector('table'));
		self::assertSame($href, $document->querySelector('td:last-child > a')->getAttribute('href'));
	}

	public static function validHrefs():iterable {
		foreach(['/applications/one/', 'application/one', '?page=2&name=Alice', '#details',
			'https://example.test/path', 'http://localhost:8080/path'] as $href) yield [$href];
	}

	public function testConstruct_controlsAndOtherLinksArePreserved():void {
		$document = new HTMLDocument('<table id="outer"><tr><td><a data-table-link href="/outer/" id="source" aria-label="Application">Name</a></td><td><button>Action</button></td><td><input></td><td><table><tr><td>Nested value</td></tr></table></td><td id="links"><span><a href="/other/">One</a></span> and <a href="/another/">Two</a></td><td id="value">Value</td></tr></table>');
		$links = $document->querySelector('#links');
		$html = $links->outerHTML;
		$source = $document->querySelector('#source');
		$sut = new TableLink($document->querySelector('#outer'));
		self::assertSame($html, $links->outerHTML);
		self::assertSame($source, $document->querySelector('#source'));
		self::assertSame('Application', $source->getAttribute('aria-label'));
		self::assertFalse($source->hasAttribute('tabindex'));
		self::assertSame('-1', $document->querySelector('#value > a')->getAttribute('tabindex'));
		self::assertNull($document->querySelector('a button, a input, a table, a a'));
	}

	public function testConstruct_unmarkedRowsAndAdditionalSourceCellLinksAreUntouched():void {
		$document = new HTMLDocument('<table><tr><td><a href="/ordinary/">Ordinary</a></td><td>Value</td></tr><tr><td><a data-table-link href="/app/">App</a> <a href="/help/">Help</a></td><td>Linked value</td></tr></table>');
		$original = $document->querySelector('tr')->outerHTML;
		$sut = new TableLink($document->querySelector('table'));
		self::assertSame($original, $document->querySelector('tr')->outerHTML);
		self::assertNull($document->querySelector('a[data-table-link]')->getAttribute('class'));
		self::assertSame('/help/', $document->querySelector('a[href="/help/"]')->getAttribute('href'));
		self::assertSame('/app/', $document->querySelector('tr:last-child td:last-child a')->getAttribute('href'));
	}

	public function testConstruct_nonTableReferenceIsRejected():void {
		$document = new HTMLDocument('<div></div>');
		$this->expectException(\InvalidArgumentException::class);
		$sut = new TableLink($document->querySelector('div'));
	}
}
