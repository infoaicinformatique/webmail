<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Heures calmes »: a time of the day (overnight too) and, if asked, the weekend, when the bell
 * of Nextcloud does not tell the new messages; the priority senders and the reminders of the
 * meetings and tasks may still come through. Pure.
 */
class QuietHours {
	public const PREF = 'quiet_hours';
	public const DEFAULTS = ['on' => false, 'from' => 19, 'to' => 8, 'weekend' => true, 'vip' => true, 'reminders' => true];

	/** @return array{on: bool, from: int, to: int, weekend: bool, vip: bool, reminders: bool} */
	public static function normalize(array $in): array {
		$out = self::DEFAULTS;
		foreach (['on', 'weekend', 'vip', 'reminders'] as $k) {
			if (array_key_exists($k, $in)) {
				$out[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOL);
			}
		}
		foreach (['from', 'to'] as $k) {
			if (isset($in[$k]) && is_numeric($in[$k]) && (int)$in[$k] >= 0 && (int)$in[$k] <= 23) {
				$out[$k] = (int)$in[$k];
			}
		}
		return $out;
	}

	public static function decode(string $json): array {
		$v = json_decode($json, true);
		return self::normalize(is_array($v) ? $v : []);
	}

	/** Within the quiet time, at this local time. */
	public static function isQuiet(array $s, \DateTimeImmutable $local): bool {
		$s = self::normalize($s);
		if (!$s['on']) {
			return false;
		}
		if ($s['weekend'] && (int)$local->format('N') >= 6) {
			return true;
		}
		$h = (int)$local->format('G');
		if ($s['from'] === $s['to']) {
			return false;
		}
		return $s['from'] < $s['to'] ? ($h >= $s['from'] && $h < $s['to']) : ($h >= $s['from'] || $h < $s['to']);
	}

	/**
	 * Not told now: a new message (« message »), one of a priority sender (« vip »), a reminder
	 * of a meeting or a task (« reminder »).
	 */
	public static function silences(array $s, \DateTimeImmutable $local, string $kind): bool {
		$s = self::normalize($s);
		if (!self::isQuiet($s, $local)) {
			return false;
		}
		return match ($kind) {
			'vip' => !$s['vip'],
			'reminder' => !$s['reminders'],
			default => true,
		};
	}
}
