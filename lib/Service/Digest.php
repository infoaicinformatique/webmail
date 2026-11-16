<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Résumé du matin »: once a day, at the hour chosen, one notification of what the day holds
 * (meetings, unread and important messages, answers to write, tasks and follow-ups due). Pure.
 */
class Digest {
	/** the hours offered (local time) */
	public const HOURS = [6, 7, 8, 9, 10];
	/** sent at most this many hours late (Nextcloud's background jobs stopped for a while) */
	public const LATE = 3;

	public static function validHour(int $hour): bool {
		return $hour === 0 || in_array($hour, self::HOURS, true);
	}

	/** Time to send it: its hour has come today (local time), not too long ago, and not sent yet today. */
	public static function due(\DateTimeImmutable $localNow, int $hour, string $lastDate): bool {
		if (!in_array($hour, self::HOURS, true)) {
			return false;
		}
		$h = (int)$localNow->format('G');
		return $h >= $hour && $h < $hour + self::LATE && $lastDate !== $localNow->format('Y-m-d');
	}

	/**
	 * What the notification says, from « Aujourd'hui » and the unread messages of the inboxes.
	 *
	 * @param array{meetings?: list<array<string, mixed>>, tasks?: list<array<string, mixed>>, flagged?: list<array<string, mixed>>, toReply?: list<array<string, mixed>>} $today
	 * @return array{meetings: int, first: string, firstStart: string, unread: int, important: int, toReply: int, tasks: int, flagged: int}
	 */
	public static function counts(array $today, int $unread, int $important, ?\DateTimeImmutable $now = null): array {
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$meetings = array_values(array_filter($today['meetings'] ?? [], fn ($m) => empty($m['over'])));
		// the first meeting still to come with an hour (the all-day ones are counted, not named)
		$timed = array_values(array_filter($meetings, fn ($m) => empty($m['allDay']) && new \DateTimeImmutable((string)$m['start']) >= $now));
		return [
			'meetings' => count($meetings),
			'first' => $timed !== [] ? (string)$timed[0]['subject'] : '',
			'firstStart' => $timed !== [] ? (string)$timed[0]['start'] : '',
			'unread' => max(0, $unread),
			'important' => max(0, min($important, $unread)),
			'toReply' => count($today['toReply'] ?? []),
			'tasks' => count($today['tasks'] ?? []),
			'flagged' => count($today['flagged'] ?? []),
		];
	}

	/** Nothing to say: no notification. */
	public static function isEmpty(array $c): bool {
		return ($c['meetings'] + $c['unread'] + $c['toReply'] + $c['tasks'] + $c['flagged']) === 0;
	}

	/** An unread message that matters: marked important, or from a priority sender. */
	public static function important(array $m, array $vip): bool {
		return ($m['importance'] ?? '') === 'High' || VipList::has($vip, $m['from'] ?? null);
	}
}
