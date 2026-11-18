<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Sans réponse »: the messages sent a few days ago that nobody answered, without having to
 * ask for a reminder when sending. One per conversation (the last one sent). Pure.
 */
class NoReply {
	public const PREF = 'noreply_ignored_';
	public const DAYS = [1, 2, 3, 5, 7, 14];
	public const DEFAULT_DAYS = 3;
	/** sent at most this many days ago: older ones are forgotten */
	public const WINDOW = 30;
	public const IGNORED_MAX = 300;

	public static function days(int $days): int {
		return in_array($days, self::DAYS, true) ? $days : self::DEFAULT_DAYS;
	}

	/**
	 * The last message sent of each conversation, sent between WINDOW and $days days ago, to
	 * someone else than oneself, not a meeting answer, not ignored. Newest first.
	 *
	 * @param list<array<string, mixed>> $sent with id, conversationId, date, displayTo, isMeeting
	 * @param list<string> $ignored
	 * @return list<array<string, mixed>>
	 */
	public static function candidates(array $sent, \DateTimeImmutable $now, int $days, array $ignored, string $me): array {
		$latest = [];
		foreach ($sent as $m) {
			$key = (string)($m['conversationId'] ?? '') !== '' ? (string)$m['conversationId'] : (string)$m['id'];
			if (!isset($latest[$key]) || strcmp((string)$m['date'], (string)$latest[$key]['date']) > 0) {
				$latest[$key] = $m;
			}
		}
		$from = $now->modify('-' . self::WINDOW . ' days');
		$to = $now->modify('-' . $days . ' days');
		$me = mb_strtolower(trim($me));
		$out = [];
		foreach ($latest as $m) {
			try {
				$date = new \DateTimeImmutable((string)$m['date']);
			} catch (\Exception) {
				continue;
			}
			$display = mb_strtolower(trim((string)($m['displayTo'] ?? '')));
			if ($date < $from || $date > $to || !empty($m['isMeeting']) || in_array((string)$m['id'], $ignored, true)
				|| $display === '' || ($me !== '' && $display === $me)) {
				continue;
			}
			$out[] = $m;
		}
		usort($out, fn ($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
		return $out;
	}

	/**
	 * Someone answered: a message of the conversation from another address than the mailbox's,
	 * received after the one sent.
	 *
	 * @param list<array<string, mixed>> $conversation
	 */
	public static function answered(array $conversation, string $sentDate, string $me): bool {
		$me = mb_strtolower(trim($me));
		foreach ($conversation as $m) {
			$from = mb_strtolower((string)($m['from']['email'] ?? ''));
			if ($from !== '' && $from !== $me && strcmp((string)$m['date'], $sentDate) > 0) {
				return true;
			}
		}
		return false;
	}

	/** Whole days since it was sent. */
	public static function age(string $date, \DateTimeImmutable $now): int {
		try {
			return max(0, (int)(($now->getTimestamp() - (new \DateTimeImmutable($date))->getTimestamp()) / 86400));
		} catch (\Exception) {
			return 0;
		}
	}

	/** @return list<string> */
	public static function decodeIgnored(string $json): array {
		$v = json_decode($json, true);
		return array_values(array_slice(array_filter(is_array($v) ? $v : [], 'is_string'), -self::IGNORED_MAX));
	}

	/** @param list<string> $ignored @return list<string> */
	public static function ignore(array $ignored, string $itemId): array {
		$ignored = array_values(array_filter($ignored, fn ($i) => $i !== $itemId));
		$ignored[] = $itemId;
		return array_slice($ignored, -self::IGNORED_MAX);
	}
}
