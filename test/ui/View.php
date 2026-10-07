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
	private \GT\WebEngine\Logic\LogicAssemblyComponentList $components;
	private array $dependencies;
	private string $page;

	public function __construct(string $page) {
		$this->page = $page;
		$this->document = new HTMLDocument(
			file_get_contents("page/_header.html")
			. file_get_contents("page/$page.html")
			. file_get_contents("page/_footer.html")
		);
		$processor = new HTMLDocumentProcessor("page/_component", "page/_partial");
		$this->components = $processor->processPartialContent($this->document);
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
		$this->dependencies = [$element, $placeholder, $table, $list, $this->lists, $cache];
		$this->binder->setDependencies(...$this->dependencies);
	}

	/** Exercise WebEngine's sibling files and scoped component binders, in runtime order. */
	public function renderAdmin(\Authwave\Session\LoginSession $login, \Gt\Input\Input $input, \Authwave\Admin\DemoWorkspace $workspace):void {
		$container = new \Gt\ServiceContainer\Container();
		$container->set($this->document, $this->binder, $login, $input, $workspace,
			new \Gt\Http\Uri("https://login.example.test/" . ($this->page === "admin/index" ? "admin" : $this->page) . "/"));
		$executor = new \GT\WebEngine\Logic\LogicExecutor("Authwave", new \Gt\ServiceContainer\Injector($container));
		if(!in_array(\GT\Routing\LogicStream\LogicStreamWrapper::STREAM_NAME, stream_get_wrappers(), true)) {
			stream_wrapper_register(\GT\Routing\LogicStream\LogicStreamWrapper::STREAM_NAME, \GT\Routing\LogicStream\LogicStreamWrapper::class);
		}
		foreach($this->components as $component) {
			$binder = new \Gt\DomTemplate\ComponentBinder($this->document);
			$binder->setDependencies(...$this->dependencies);
			$binder->setComponentBinderDependencies($component->component);
			foreach($executor->invoke($component->assembly, "go", [
				\Gt\Dom\Element::class => $component->component, \Gt\DomTemplate\Binder::class => $binder,
			]) as $_) {}
		}
		$assembly = new Assembly();
		$assembly->add("page/$this->page.php");
		foreach($executor->invoke($assembly, "go") as $_) {}
		$common = new Assembly();
		$common->add("page/admin/_common.php");
		foreach($executor->invoke($common, "go_after") as $_) {}
	}
}
