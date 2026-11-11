<?php
namespace Authwave\Admin\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use ValueError;

readonly class ReportingPeriod {
	public DateTimeImmutable $start;
	public DateTimeImmutable $end;
	public DateTimeImmutable $previousStart;
	public int $days;

	public function __construct(
		public string $period = "30d",
		?string $from = null,
		?string $until = null,
		?DateTimeImmutable $now = null,
	) {
		$today = ($now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone("UTC"))
			->setTime(0, 0);

		if($period === "custom") {
			$start = $this->date($from);
			$last = $this->date($until);

			if($last < $start || $last > $today || $start->diff($last)->days >= 366) {
				throw new InvalidArgumentException("Choose up to 366 days, ending today or earlier.");
			}

			$this->start = $start;
			$this->end = $last->modify("+1 day");
		}
		else {
			$days = match($period) {
				"30d" => 30,
				"7d" => 7,
				"24h" => 1,
				default => throw new InvalidArgumentException("Choose a valid reporting period."),
			};
			$this->end = $today->modify("+1 day");
			$this->start = $this->end->modify("-$days days");
		}

		$this->days = (int)$this->start->diff($this->end)->days;
		$this->previousStart = $this->start->modify("-$this->days days");
	}

	/** @SuppressWarnings("PHPMD.StaticAccess") */
	private function date(?string $value):DateTimeImmutable {
		try {
			$date = DateTimeImmutable::createFromFormat("!Y-m-d", $value ?? "", new DateTimeZone("UTC"));
		}
		catch(ValueError) {
			throw new InvalidArgumentException("Choose valid reporting dates.");
		}

		if(!$date || $date->format("Y-m-d") !== $value) {
			throw new InvalidArgumentException("Choose valid reporting dates.");
		}

		return $date;
	}
}
