<?php
namespace Authwave\Admin\Audit;

use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, AuditLogEntry> */
readonly class AuditLog implements IteratorAggregate, Countable {
	/** @param list<AuditLogEntry> $entries */
	public function __construct(
		private array $entries,
		public int $page,
		public bool $hasNext,
	) {}

	/** @return Traversable<int, AuditLogEntry> */
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
