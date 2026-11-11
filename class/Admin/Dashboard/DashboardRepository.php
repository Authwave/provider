<?php
namespace Authwave\Admin\Dashboard;

use Authwave\Model\User;
use DateTimeImmutable;
use GT\Database\Query\QueryCollection;
use GT\Database\Result\Row;
use InvalidArgumentException;

readonly class DashboardRepository {
	const PAGE_SIZE = 25;
	const ACTIONS = ["LOGIN_COMPLETED", "PASSWORD_LOGIN_FAILED", "SECURITY_CODE_REJECTED",
		"PASSWORD_CHANGED", "SECURITY_CODE_REQUESTED", "LOGOUT_REQUESTED"];

	public function __construct(private QueryCollection $queries) {}

	public function totals(User $actor, string $applicationId,
		DateTimeImmutable $start, DateTimeImmutable $end, ?string $deploymentId = null):Row {
		return $this->queries->fetch("totals",
			$this->scope($actor, $applicationId, $start, $end, $deploymentId));
	}

	public function newUserCount(User $actor, string $applicationId,
		DateTimeImmutable $start, DateTimeImmutable $end, ?string $deploymentId = null):int {
		return $this->queries->fetchInt("newUserCount",
			$this->scope($actor, $applicationId, $start, $end, $deploymentId));
	}

	/** @return list<Row> */
	public function series(User $actor, string $applicationId, ReportingPeriod $period,
		?string $deploymentId = null):array {
		$hourly = $period->days === 1;
		$format = $hourly ? "Y-m-d H" : "Y-m-d";
		$interval = $hourly ? "+1 hour" : "+1 day";
		$rows = $this->queries->fetchAll("series",
			$this->scope($actor, $applicationId, $period->start, $period->end, $deploymentId)
				+ ["bucketLength" => $hourly ? 13 : 10]);
		$indexed = [];

		foreach($rows as $row) {
			$indexed[$row->getString("bucket")] = $row;
		}

		$series = [];

		for($time = $period->start; $time < $period->end; $time = $time->modify($interval)) {
			$row = $indexed[$time->format($format)] ?? null;
			$series []= new Row([
				"bucket" => $time->format($hourly ? "H:00" : "Y-m-d"),
				"logins" => (string)($row?->getInt("logins") ?? 0),
				"failures" => (string)($row?->getInt("failures") ?? 0),
			]);
		}

		return $series;
	}

	/** @return list<Row> One extra row indicates whether a next page exists. */
	public function activity(User $actor, string $applicationId, ReportingPeriod $period,
		string $action = "", int $page = 1, ?string $deploymentId = null):array {
		if(($action !== "" && !in_array($action, self::ACTIONS, true))
		|| $page < 1 || $page > intdiv(PHP_INT_MAX, self::PAGE_SIZE)) {
			throw new InvalidArgumentException("Choose a valid activity filter and page.");
		}

		return iterator_to_array($this->queries->fetchAll("activity",
			$this->scope($actor, $applicationId, $period->start, $period->end, $deploymentId) + [
				"filter" => $action, "action" => $action,
				"limit" => self::PAGE_SIZE + 1, "offset" => ($page - 1) * self::PAGE_SIZE,
			]));
	}

	/** @return list<Row> */
	public function topUsers(User $actor, string $applicationId, ReportingPeriod $period,
		?string $deploymentId = null):array {
		return iterator_to_array($this->queries->fetchAll("topUsers",
			$this->scope($actor, $applicationId, $period->start, $period->end, $deploymentId)));
	}

	/** @return list<Row> */
	public function newUsers(User $actor, string $applicationId, ReportingPeriod $period,
		?string $deploymentId = null):array {
		return iterator_to_array($this->queries->fetchAll("newUsers",
			$this->scope($actor, $applicationId, $period->start, $period->end, $deploymentId)));
	}

	/** @return list<Row> */
	public function awaitingFirstLogin(User $actor, string $applicationId,
		ReportingPeriod $period, ?string $deploymentId = null):array {
		return iterator_to_array($this->queries->fetchAll("awaitingFirstLogin",
			$this->scope($actor, $applicationId, $period->start, $period->end, $deploymentId)));
	}

	/** @return array<string, string|DateTimeImmutable|null> */
	private function scope(User $actor, string $applicationId,
		DateTimeImmutable $start, DateTimeImmutable $end, ?string $deploymentId):array {
		return ["actorId" => $actor->id, "applicationId" => $applicationId,
			"start" => $start, "end" => $end,
			"deploymentFilter" => $deploymentId, "deploymentId" => $deploymentId];
	}
}
