<?php
namespace Authwave\Email;

use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, array<string, string>> */
readonly class EmailLog implements IteratorAggregate, Countable {
	/** @param list<array<string, string>> $entries */
	public function __construct(
		private array $entries,
		public int $page,
		public bool $hasNext,
	) {}

	/** @return Traversable<int, array<string, string>> */
	public function getIterator():Traversable {
		yield from $this->entries;
	}

	public function count():int {
		return count($this->entries);
	}

	public function hasPrevious():bool {
		return $this->page > 1;
	}
}
