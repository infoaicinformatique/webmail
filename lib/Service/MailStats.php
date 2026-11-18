<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Statistiques »: what the mailbox received and sent over a period — by day, by hour,
 * by weekday, the people who write the most and those written to the most, and the time
 * taken to answer. Pure.
 */
class MailStats {
	public const PERIODS = [7, 30, 90];
	public const DEFAULT_DAYS = 30;
	/** messages of each folder read at most */
	public const MAX = 1500;
	/** a reply this late is not counted as one (another subject in the same conversation) */
	private const REPLY_WITHIN = 14 * 86400;
	private const TOP = 10;

	public static function days(int $days): int {
		return in_array($days, self::PERIODS, true) ? $days : self::DEFAULT_DAYS;
	}

	/**
	 * @param list<array<string, mixed>> $received the newest messages of the inbox
	 * @param list<array<string, mixed>> $sent the newest messages sent
	 * @return array<string, mixed>
	 */
	public static function compute(array $received, array $sent, int $days, \DateTimeZone $tz, \DateTimeImmutable $now, string $me = ''): array {
		$days = self::days($days);
		$today = $now->setTimezone($tz)->setTime(0, 0);
		$start = $today->modify('-' . ($days - 1) . ' days');
		$since = $start->getTimestamp();
		$me = mb_strtolower(trim($me));
		$in = self::within($received, $since);
		$out = self::within($sent, $since);

		$perDay = [];
		for ($d = $start; $d <= $today; $d = $d->modify('+1 day')) {
			$perDay[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d'), 'received' => 0, 'sent' => 0];
		}
		$hours = array_fill(0, 24, 0);
		$weekdays = array_fill(0, 7, 0); // Monday first
		$senders = [];
		$unread = 0;
		foreach ($in as [$m, $t]) {
			$local = (new \DateTimeImmutable('@' . $t))->setTimezone($tz);
			$key = $local->format('Y-m-d');
			if (isset($perDay[$key])) {
				$perDay[$key]['received']++;
			}
			$hours[(int)$local->format('G')]++;
			$weekdays[(int)$local->format('N') - 1]++;
			if (empty($m['isRead'])) {
				$unread++;
			}
			$email = mb_strtolower(trim((string)($m['from']['email'] ?? '')));
			if ($email === '' || $email === $me) {
				continue;
			}
			$senders[$email] ??= ['name' => (string)($m['from']['name'] ?? ''), 'email' => $email, 'count' => 0];
			$senders[$email]['count']++;
		}
		$recipients = [];
		foreach ($out as [$m, $t]) {
			$key = (new \DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d');
			if (isset($perDay[$key])) {
				$perDay[$key]['sent']++;
			}
			foreach (preg_split('/\s*;\s*/', trim((string)($m['displayTo'] ?? ''))) ?: [] as $name) {
				if ($name === '') {
					continue;
				}
				$k = mb_strtolower($name);
				$recipients[$k] ??= ['name' => $name, 'count' => 0];
				$recipients[$k]['count']++;
			}
		}
		$delays = self::replyDelays($received, $out);
		return [
			'days' => $days,
			'choices' => self::PERIODS,
			'received' => count($in),
			'sent' => count($out),
			'unread' => $unread,
			'perDay' => array_values($perDay),
			'hours' => $hours,
			'weekdays' => $weekdays,
			'topSenders' => self::top($senders),
			'topRecipients' => self::top($recipients),
			'replied' => count($delays),
			'replyMedian' => self::median($delays),
			// the oldest message read is still in the period: there may be more than counted
			'partial' => self::cut($received, $since) || self::cut($sent, $since),
		];
	}

	/** @return list<array{0: array<string, mixed>, 1: int}> */
	private static function within(array $items, int $since): array {
		$out = [];
		foreach ($items as $m) {
			$t = self::time($m);
			if ($t !== null && $t >= $since) {
				$out[] = [$m, $t];
			}
		}
		return $out;
	}

	private static function time(array $m): ?int {
		$d = (string)($m['date'] ?? '');
		if ($d === '') {
			return null;
		}
		try {
			return (new \DateTimeImmutable($d))->getTimestamp();
		} catch (\Exception) {
			return null;
		}
	}

	private static function cut(array $items, int $since): bool {
		if (count($items) < self::MAX) {
			return false;
		}
		$t = self::time($items[count($items) - 1]);
		return $t !== null && $t >= $since;
	}

	/**
	 * How long each answer took, in minutes: from the last message received in its
	 * conversation before it.
	 * @param list<array<string, mixed>> $received
	 * @param list<array{0: array<string, mixed>, 1: int}> $sent
	 * @return list<int>
	 */
	public static function replyDelays(array $received, array $sent): array {
		$byConv = [];
		foreach ($received as $m) {
			$c = (string)($m['conversationId'] ?? '');
			$t = self::time($m);
			if ($c !== '' && $t !== null) {
				$byConv[$c][] = $t;
			}
		}
		$out = [];
		foreach ($sent as [$m, $t]) {
			$c = (string)($m['conversationId'] ?? '');
			$before = array_filter($byConv[$c] ?? [], fn ($r) => $r < $t && $t - $r <= self::REPLY_WITHIN);
			if ($before !== []) {
				$out[] = intdiv($t - max($before), 60);
			}
		}
		return $out;
	}

	/** @param list<int> $values */
	public static function median(array $values): ?int {
		if ($values === []) {
			return null;
		}
		sort($values);
		$n = count($values);
		return $n % 2 ? $values[intdiv($n, 2)] : intdiv($values[$n / 2 - 1] + $values[$n / 2], 2);
	}

	/** @return list<array<string, mixed>> */
	private static function top(array $counts): array {
		$list = array_values($counts);
		usort($list, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp((string)$a['name'], (string)$b['name']));
		return array_slice($list, 0, self::TOP);
	}
}
