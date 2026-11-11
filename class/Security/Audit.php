<?php
namespace Authwave\Security;

use Authwave\Model\User;
use Authwave\User\{UserRepository, LoginState};
use Authwave\Session\LoginSession;
use GT\Http\Request;
use GT\Database\Query\QueryCollection;
use GT\Ulid\Ulid;
use stdClass;

class Audit {
	private bool $requestRecorded = false;

	public function __construct(
		private readonly QueryCollection $database,
	) {}

	public function recordRequest(Request $request, LoginSession $login,
		UserRepository $users, AnonUser $anonymous):void {
		if($this->requestRecorded) {
			return;
		}

		$actor = $anonymous;

		if($login->getState() === LoginState::LOGGED_IN
		&& ($deployment = $login->getDeployment()) && ($email = $login->getEmail())) {
			$actor = $users->get($deployment, $email) ?? $anonymous;
		}

		$this->create(Action::REQUEST_RECEIVED,
			["method" => $request->getMethod(), "path" => $request->getUri()->getPath()], $actor);
		$this->requestRecorded = true;
	}

	/** @param array<string, mixed> $context */
	public function recordChange(Action $action, ?object $before, ?object $after,
		User|AnonUser|null $actor, array $context = []):void {
		$detail = $context;
		$before = $before === null ? [] : get_object_vars($before);
		$after = $after === null ? [] : get_object_vars($after);

		foreach(array_intersect(array_keys($before), array_keys($after)) as $field) {
			if(json_encode($before[$field], JSON_THROW_ON_ERROR)
				=== json_encode($after[$field], JSON_THROW_ON_ERROR)) {
				unset($before[$field], $after[$field]);
			}
		}

		if($before !== []) {
			$detail["from"] = (object)$before;
		}

		if($after !== []) {
			$detail["to"] = (object)$after;
		}

		$this->create($action, $detail, $actor);
	}

	/** @param null|array<string, mixed> $detail */
	public function create(
		Action $action,
		?array $detail,
		null|User|AnonUser $user = null,
	):void {
		$detail = $this->sanitise($detail);

		if($detail === []) {
			$detail = null;
		}

		$this->database->insert("create", [
			"id" => new Ulid("AUDIT"),
			"action" => $action->name,
			"detail" => $detail === null ? null : json_encode($detail,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			"userId" => $user instanceof User ? $user->id : null,
			"anonUserId" => $user instanceof AnonUser ? $user->id : null,
		]);
	}

	/** Credentials do not belong in an audit trail, even as changed values. */
	private function sanitise(mixed $value):mixed {
		if(!is_object($value) && !is_array($value)) {
			return $value;
		}

		$result = is_array($value) ? [] : new stdClass();

		foreach($value as $key => $item) {
			if(preg_match('/password|hash|token|secret|code|cipher|api.?key|(^|_)iv$/i', (string)$key)) {
				continue;
			}

			$clean = $this->sanitise($item);

			if(is_array($result)) {
				$result[$key] = $clean;
			}
			else {
				$result->$key = $clean;
			}
		}

		return $result;
	}
}
