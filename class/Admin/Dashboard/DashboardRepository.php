<?php
namespace Authwave\Admin\Dashboard;

use Authwave\Model\User;
use Authwave\Security\Action;
use DateTimeImmutable;
use DateTimeZone;
use GT\Database\Query\QueryCollection;
use InvalidArgumentException;

readonly class DashboardRepository {
	const PAGE_SIZE = 25;
	const ACTIONS = ["LOGIN_COMPLETED", "PASSWORD_LOGIN_FAILED", "SECURITY_CODE_REJECTED",
		"PASSWORD_CHANGED", "SECURITY_CODE_REQUESTED", "LOGOUT_REQUESTED"];

	public function __construct(private QueryCollection $queries) {}

	public function totals(User $actor, string $applicationId,
		DateTimeImmutable $start, DateTimeImmutable $end, ?string $deploymentId = null):DashboardTotals {
		$row = $this->queries->fetch("totals",
			$this->scope($actor, $applicationId, $start, $end, $deploymentId));

		return new DashboardTotals($row->getInt("logins"), $row->getInt("failures"),
			$row->getInt("codes"), $row->getInt("passwords"));
	}

	public function newUserCount(User $actor, string $applicationId,
		DateTimeImmutable $start, DateTimeImmutable $end, ?string $deploymentId = null):int {
		return $this->queries->fetchInt("newUserCount",
			$this->scope($actor, $applicationId, $start, $end, $deploymentId));
	}

	/** @return list<DashboardSeriesPoint> */
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
			$series []= new DashboardSeriesPoint(
				$time->format($hourly ? "H:00" : "Y-m-d"),
				$row?->getInt("logins") ?? 0,
				$row?->getInt("failures") ?? 0,
			);
		}

		return $series;
	}

	public function activity(User $actor, string $applicationId, ReportingPeriod $period,
		string $action = "", int $page = 1, ?string $deploymentId = null):DashboardActivity {
		if(($action !== "" && !in_array($action, self::ACTIONS, true))
		|| $page < 1 || $page > intdiv(PHP_INT_MAX, self::PAGE_SIZE)) {
			throw new InvalidArgumentException("Choose a valid activity filter and page.");
		}

		$rows = $this->queries->fetchAll("activity",
			$this->scope($actor, $applicationId, $period->start, $period->end, $deploymentId) + [
				"filter" => $action, "action" => $action,
				"limit" => self::PAGE_SIZE + 1, "offset" => ($page - 1) * self::PAGE_SIZE,
			]);
		$events = [];
		$hasNext = false;

		foreach($rows as $row) {
			if(count($events) === self::PAGE_SIZE) {
				$hasNext = true;
				break;
			}

			$events []= new DashboardActivityEvent(
				$row->getString("id"), constant(Action::class . "::" . $row->getString("action")),
				new DateTimeImmutable($row->getString("createdAt"), new DateTimeZone("UTC")),
				$row->getString("userId"), $row->getString("email"),
				$row->getString("deploymentId"), $row->getString("title"),
			);
		}

		return new DashboardActivity($events, $page, $hasNext);
	}

	/** @return list<DashboardTopUser> */
	public function topUsers(User $actor, string $applicationId, ReportingPeriod $period,
		?string $deploymentId = null):array {
		$rows = $this->queries->fetchAll("topUsers",
			$this->scope($actor, $applicationId, $period->start, $period->end, $deploymentId));
		$users = [];

		foreach($rows as $row) {
			$users []= new DashboardTopUser($row->getString("userId"), $row->getString("email"),
				$row->getString("title"), $row->getInt("logins"));
		}

		return $users;
	}

	/** @return list<DashboardNewUser> */
	public function newUsers(User $actor, string $applicationId, ReportingPeriod $period,
		?string $deploymentId = null):array {
		$rows = $this->queries->fetchAll("newUsers",
			$this->scope($actor, $applicationId, $period->start, $period->end, $deploymentId));
		$users = [];

		foreach($rows as $row) {
			$users []= new DashboardNewUser($row->getString("userId"), $row->getString("email"),
				new DateTimeImmutable($row->getString("createdAt"), new DateTimeZone("UTC")),
				$row->getString("title"));
		}

		return $users;
	}

	/** @return list<DashboardNewUser> */
	public function awaitingFirstLogin(User $actor, string $applicationId,
		ReportingPeriod $period, ?string $deploymentId = null):array {
		$rows = $this->queries->fetchAll("awaitingFirstLogin",
			$this->scope($actor, $applicationId, $period->start, $period->end, $deploymentId));
		$users = [];

		foreach($rows as $row) {
			$users []= new DashboardNewUser($row->getString("userId"), $row->getString("email"),
				new DateTimeImmutable($row->getString("createdAt"), new DateTimeZone("UTC")),
				$row->getString("title"));
		}

		return $users;
	}

	/** @return array<string, string|DateTimeImmutable|null> */
	private function scope(User $actor, string $applicationId,
		DateTimeImmutable $start, DateTimeImmutable $end, ?string $deploymentId):array {
		return ["actorId" => $actor->id, "applicationId" => $applicationId,
			"start" => $start, "end" => $end,
			"deploymentFilter" => $deploymentId, "deploymentId" => $deploymentId];
	}
}
