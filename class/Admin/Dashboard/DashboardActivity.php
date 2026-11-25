<?php
namespace Authwave\Admin\Dashboard;

use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, DashboardActivityEvent> */
readonly class DashboardActivity implements IteratorAggregate, Countable {
	/** @param list<DashboardActivityEvent> $events */
	public function __construct(
		private array $events,
		public int $page,
		public bool $hasNext,
	) {}

	/** @return Traversable<int, DashboardActivityEvent> */
	public function getIterator():Traversable {
		yield from $this->events;
	}

	public function count():int {
		return count($this->events);
	}

	public function hasPrevious():bool {
		return $this->page > 1;
	}
}
