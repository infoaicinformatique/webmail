<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Proposer des créneaux »: the free slots offered, and the link that names them
 * (pure functions, tested without a server).
 */
class BookingRules {
	/** working hours of the slots offered (in the organiser's time zone) */
	public const DAY_START = 9;
	public const DAY_END = 18;
	/** a slot offered starts this long from now at the soonest */
	public const NOTICE_MINUTES = 120;
	/** slots are looked for every this many minutes */
	public const STEP_MINUTES = 30;

	/**
	 * The token of a proposal: its owner (readable, not secret) and a secret part. The public
	 * page finds the proposal by both, never by the owner alone.
	 */
	public static function token(string $userId, string $secret): string {
		return rtrim(strtr(base64_encode($userId), '+/', '-_'), '=') . '.' . $secret;
	}

	/** @return ?array{0: string, 1: string} the owner and the secret part, null when it is no token */
	public static function parse(string $token): ?array {
		if (!preg_match('~^([A-Za-z0-9_-]{1,200})\.([A-Za-z0-9]{24})$~', $token, $m)) {
			return null;
		}
		$user = base64_decode(strtr($m[1], '-_', '+/'), true);
		return $user !== false && $user !== '' ? [$user, $m[2]] : null;
	}

	/**
	 * Free slots on the working days to come, spread over the days (the first free one of each
	 * day, then the next ones), in time order.
	 * @param list<array{start: string, end: string}> $busy the busy times (any zone)
	 * @return list<array{start: string, end: string}> in UTC (ISO 8601)
	 */
	public static function slots(array $busy, \DateTimeImmutable $now, \DateTimeZone $zone, int $days, int $minutes, int $count): array {
		$minutes = max(15, min(240, $minutes));
		$count = max(1, min(10, $count));
		$days = max(1, min(30, $days));
		$blocks = [];
		foreach ($busy as $b) {
			try {
				$blocks[] = [(new \DateTimeImmutable($b['start']))->getTimestamp(), (new \DateTimeImmutable($b['end']))->getTimestamp()];
			} catch (\Exception) {
			}
		}
		$soonest = $now->getTimestamp() + self::NOTICE_MINUTES * 60;
		$perDay = [];
		$local = $now->setTimezone($zone)->setTime(0, 0);
		for ($d = 0; $d <= $days; $d++) {
			$day = $local->modify('+' . $d . ' days');
			if ((int)$day->format('N') >= 6) {
				continue;
			}
			$free = [];
			for ($t = $day->setTime(self::DAY_START, 0); ; $t = $t->modify('+' . self::STEP_MINUTES . ' minutes')) {
				$end = $t->modify('+' . $minutes . ' minutes');
				if ($end > $day->setTime(self::DAY_END, 0)) {
					break;
				}
				$s = $t->getTimestamp();
				$e = $end->getTimestamp();
				if ($s < $soonest) {
					continue;
				}
				foreach ($blocks as [$bs, $be]) {
					if ($bs < $e && $be > $s) {
						continue 2;
					}
				}
				$free[] = [$s, $e];
			}
			if ($free !== []) {
				$perDay[] = $free;
			}
		}
		// round robin: the first slot of each day, then the second… (several days to choose from)
		$picked = [];
		for ($round = 0; count($picked) < $count; $round++) {
			$any = false;
			foreach ($perDay as $free) {
				// (the slots of a day kept apart: every other one)
				$i = $round * 2;
				if (isset($free[$i]) && count($picked) < $count) {
					$picked[] = $free[$i];
					$any = true;
				}
			}
			if (!$any) {
				break;
			}
		}
		usort($picked, fn ($a, $b) => $a[0] <=> $b[0]);
		return array_map(fn ($p) => ['start' => gmdate('Y-m-d\TH:i:s\Z', $p[0]), 'end' => gmdate('Y-m-d\TH:i:s\Z', $p[1])], $picked);
	}
}
