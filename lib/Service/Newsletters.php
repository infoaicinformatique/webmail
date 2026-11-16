<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Lettres d'information »: the mailing lists of a mailbox, one row per sender, with how
 * many messages, how heavy and how often opened, to leave the useless ones in one click.
 * The senders kept on purpose (« Garder ») are no longer shown. Pure.
 */
class Newsletters {
	public const PREF = 'newsletters_kept_';
	public const KEPT_MAX = 500;

	/**
	 * One row per sender, the most messages first (then the heaviest).
	 *
	 * @param list<array{id: string, email: string, name: string, isRead: bool, size: int, date: ?string, method: string}> $messages newest first
	 * @param list<string> $kept
	 * @return list<array{email: string, name: string, count: int, unread: int, bytes: int, readRate: int, last: ?string, itemId: string, method: string}>
	 */
	public static function group(array $messages, array $kept = []): array {
		$rows = [];
		foreach ($messages as $m) {
			$email = mb_strtolower(trim((string)$m['email']));
			if ($email === '' || in_array($email, $kept, true)) {
				continue;
			}
			if (!isset($rows[$email])) {
				// the newest message gives the name, the date and how to leave the list
				$rows[$email] = ['email' => $email, 'name' => trim((string)$m['name']), 'count' => 0, 'unread' => 0, 'bytes' => 0,
					'readRate' => 0, 'last' => $m['date'], 'itemId' => (string)$m['id'], 'method' => (string)$m['method']];
			}
			$rows[$email]['count']++;
			$rows[$email]['unread'] += $m['isRead'] ? 0 : 1;
			$rows[$email]['bytes'] += max(0, (int)$m['size']);
		}
		foreach ($rows as &$r) {
			$r['readRate'] = (int)round(100 * ($r['count'] - $r['unread']) / $r['count']);
		}
		unset($r);
		$rows = array_values($rows);
		usort($rows, fn ($a, $b) => [$b['count'], $b['bytes'], $a['email']] <=> [$a['count'], $a['bytes'], $b['email']]);
		return $rows;
	}

	/** @return list<string> */
	public static function decodeKept(string $json): array {
		$v = json_decode($json, true);
		$out = [];
		foreach (is_array($v) ? $v : [] as $e) {
			$e = is_string($e) ? mb_strtolower(trim($e)) : '';
			if (filter_var($e, FILTER_VALIDATE_EMAIL) && !in_array($e, $out, true)) {
				$out[] = $e;
			}
		}
		return array_slice($out, 0, self::KEPT_MAX);
	}

	/**
	 * @param list<string> $kept
	 * @return list<string>
	 * @throws \InvalidArgumentException
	 */
	public static function keep(array $kept, string $email, bool $keep): array {
		$email = mb_strtolower(trim($email));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new \InvalidArgumentException('address');
		}
		$kept = array_values(array_filter($kept, fn ($e) => $e !== $email));
		if ($keep) {
			// the oldest choice forgotten first when the list is full
			$kept[] = $email;
			$kept = array_slice($kept, -self::KEPT_MAX);
		}
		return $kept;
	}
}
