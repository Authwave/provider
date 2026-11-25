<?php
namespace Authwave\Admin\Audit;

use Authwave\Admin\Dashboard\ReportingPeriod;
use Authwave\Model\User;
use Authwave\Security\Action;
use DateTimeImmutable;
use DateTimeZone;
use GT\Database\Query\QueryCollection;
use InvalidArgumentException;

readonly class AuditRepository {
	const PAGE_SIZE = 25;

	public function __construct(private QueryCollection $queries) {}

	/** @SuppressWarnings("PHPMD.StaticAccess") */
	public function log(User $actor, string $applicationId, ReportingPeriod $period,
		string $action = "", int $page = 1, string $deploymentId = ""):AuditLog {
		$actions = array_map(fn(Action $event) => $event->name, Action::cases());

		if(($action !== "" && !in_array($action, $actions, true))
		|| $page < 1 || $page > intdiv(PHP_INT_MAX, self::PAGE_SIZE)) {
			throw new InvalidArgumentException("Choose a valid audit event and page.");
		}

		$rows = $this->queries->fetchAll("log", [
			"actorId" => $actor->id, "applicationId" => $applicationId,
			"fallbackApplicationId" => $applicationId, "scopeApplicationId" => $applicationId,
			"start" => $period->start, "end" => $period->end,
			"filter" => $action, "action" => $action,
			"deploymentFilter" => $deploymentId, "deploymentId" => $deploymentId,
			"contextDeploymentId" => $deploymentId,
			"limit" => self::PAGE_SIZE + 1, "offset" => ($page - 1) * self::PAGE_SIZE,
		]);
		$entries = [];
		$hasNext = false;

		foreach($rows as $row) {
			if(count($entries) === self::PAGE_SIZE) {
				$hasNext = true;
				break;
			}

			$entries []= new AuditLogEntry(
				$row->getString("id"), constant(Action::class . "::" . $row->getString("action")),
				new DateTimeImmutable($row->getString("createdAt"), new DateTimeZone("UTC")),
				$row->getString("email"),
				$row->getString("title") ?? ($row->getInt("hasDeployment") === 1
					? "Removed deployment" : "Application-wide"),
			);
		}

		return new AuditLog($entries, $page, $hasNext);
	}
}
