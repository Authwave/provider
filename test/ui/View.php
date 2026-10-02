<?php
namespace Authwave\Test\UI;

use GT\Dom\HTMLDocument;
use GT\DomTemplate\{BindableCache, DocumentBinder, ElementBinder, HTMLAttributeBinder, HTMLAttributeCollection, ListBinder, ListElementCollection, PlaceholderBinder, TableBinder};
use GT\WebEngine\Logic\HTMLDocumentProcessor;
use GT\Routing\Assembly;
use GT\Routing\Path\DynamicPath;

/** Renders the real templates without starting the application, database or mailer. */
class View {
	public HTMLDocument $document;
	public DocumentBinder $binder;
	public ListElementCollection $lists;

	public function __construct(string $page) {
		$this->document = new HTMLDocument(
			file_get_contents("page/_header.html")
			. file_get_contents("page/$page.html")
			. file_get_contents("page/_footer.html")
		);
		$processor = new HTMLDocumentProcessor("page/_component", "page/_partial");
		$processor->processPartialContent($this->document);
		$assembly = new Assembly();
		$assembly->add("page/$page.html");
		$processor->processDynamicPath($this->document, new DynamicPath("/$page/", $assembly));
		$attributes = new HTMLAttributeBinder();
		$collection = new HTMLAttributeCollection();
		$element = new ElementBinder();
		$placeholder = new PlaceholderBinder();
		$table = new TableBinder();
		$list = new ListBinder();
		$this->lists = new ListElementCollection($this->document);
		$cache = new BindableCache();
		$attributes->setDependencies($list, $table);
		$element->setDependencies($attributes, $collection, $placeholder);
		$table->setDependencies($list, $this->lists, $element, $attributes, $collection, $placeholder);
		$list->setDependencies($element, $this->lists, $cache, $table);
		$this->binder = new DocumentBinder($this->document);
		$this->binder->setDependencies($element, $placeholder, $table, $list, $this->lists, $cache);
	}
}
