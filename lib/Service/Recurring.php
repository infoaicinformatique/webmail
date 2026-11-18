<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Envoi récurrent »: a message sent again and again (every day, every working day, every
 * week on a day, every month on a date), at a time of the user's time zone. Pure.
 */
class Recurring {
	public const PREF = 'recurring_';
	public const BODY = 'recurring_body_';
	public const FREQS = ['daily', 'weekdays', 'weekly', 'monthly'];
	public const MAX = 20;
	public const BODY_MAX = 60000;
	/** failures in a row before the sending is paused */
	public const MAX_ERRORS = 5;

	/**
	 * The schedule, checked: freq, weekday (1 Monday … 7 Sunday), monthday (1–28), time (HH:MM), tz.
	 * @return array{freq: string, weekday: int, monthday: int, time: string, tz: string}
	 * @throws \InvalidArgumentException
	 */
	public static function schedule(array $in): array {
		$freq = (string)($in['freq'] ?? '');
		if (!in_array($freq, self::FREQS, true)) {
			throw new \InvalidArgumentException('freq');
		}
		$time = (string)($in['time'] ?? '');
		if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
			throw new \InvalidArgumentException('time');
		}
		$tz = (string)($in['tz'] ?? 'UTC');
		try {
			new \DateTimeZone($tz);
		} catch (\Exception) {
			$tz = 'UTC';
		}
		$weekday = (int)($in['weekday'] ?? 1);
		$monthday = (int)($in['monthday'] ?? 1);
		return [
			'freq' => $freq,
			'weekday' => $weekday >= 1 && $weekday <= 7 ? $weekday : 1,
			'monthday' => $monthday >= 1 && $monthday <= 28 ? $monthday : 1,
			'time' => $time,
			'tz' => $tz,
		];
	}

	/** The next sending strictly after $after (UTC, ISO 8601). */
	public static function next(array $s, \DateTimeImmutable $after): string {
		$zone = new \DateTimeZone($s['tz']);
		[$h, $m] = array_map('intval', explode(':', $s['time']));
		$day = $after->setTimezone($zone)->setTime($h, $m);
		for ($i = 0; $i < 400; $i++, $day = $day->modify('+1 day')->setTime($h, $m)) {
			if ($day <= $after) {
				continue;
			}
			$n = (int)$day->format('N');
			$ok = match ($s['freq']) {
				'daily' => true,
				'weekdays' => $n <= 5,
				'weekly' => $n === $s['weekday'],
				'monthly' => (int)$day->format('j') === $s['monthday'],
				default => false,
			};
			if ($ok) {
				return $day->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
			}
		}
		throw new \LogicException('no next date');
	}

	/** @return list<array<string, mixed>> */
	public static function decode(string $json): array {
		$v = json_decode($json, true);
		return array_values(array_filter(is_array($v) ? $v : [], fn ($e) => is_array($e) && isset($e['id'], $e['next'], $e['freq'])));
	}

	/** Due now: not paused, its time has come. */
	public static function due(array $e, \DateTimeImmutable $now): bool {
		return empty($e['paused']) && new \DateTimeImmutable((string)$e['next']) <= $now;
	}
}
