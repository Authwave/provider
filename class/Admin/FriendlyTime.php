<?php
namespace Authwave\Admin;

use DateTimeImmutable;

class FriendlyTime {
	public static function format(DateTimeImmutable $date, ?DateTimeImmutable $now = null):string {
		$now ??= new DateTimeImmutable();
		$date = $date->setTimezone($now->getTimezone());
		$seconds = max(0, $now->getTimestamp() - $date->getTimestamp());
		if($seconds < 60) return "Just now";
		if($date->format("Y-m-d") === $now->format("Y-m-d")) {
			$count = (int)floor($seconds / ($seconds < 3600 ? 60 : 3600));
			$unit = $seconds < 3600 ? "minute" : "hour";
			return "$count $unit" . ($count === 1 ? "" : "s") . " ago";
		}
		if($date->format("Y-m-d") === $now->modify("-1 day")->format("Y-m-d")) {
			return "Yesterday " . match(true) {
				(int)$date->format("G") < 12 => "morning",
				(int)$date->format("G") < 18 => "afternoon",
				default => "evening",
			};
		}
		$days = (int)$date->setTime(0, 0)->diff($now->setTime(0, 0))->days;
		return $days < 7 ? "$days days ago" : $date->format("j M Y");
	}
}
