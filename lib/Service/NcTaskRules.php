<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * Tasks between Exchange and Nextcloud (the Tasks app: VTODO of RFC 5545), without
 * Nextcloud: the words of one in those of the other, and the Exchange tasks written
 * as iCalendar for the Tasks app. Tested without a server.
 */
final class NcTaskRules {
	/** Exchange's importance of a PRIORITY (1 highest … 9 lowest, 0 none). */
	public static function importance(int $priority): string {
		return match (true) {
			$priority >= 1 && $priority <= 4 => 'High',
			$priority >= 6 && $priority <= 9 => 'Low',
			default => 'Normal',
		};
	}

	/** The PRIORITY of an importance, as the Tasks app writes it (0: none). */
	public static function priority(string $importance): int {
		return match ($importance) {
			'High' => 1,
			'Low' => 9,
			default => 0,
		};
	}

	/** Exchange's state of a VTODO (STATUS, PERCENT-COMPLETE). */
	public static function status(string $status, int $percent = 0): string {
		return match (strtoupper($status)) {
			'COMPLETED' => 'Completed',
			'IN-PROCESS' => 'InProgress',
			'CANCELLED' => 'Deferred',
			default => $percent >= 100 ? 'Completed' : ($percent > 0 ? 'InProgress' : 'NotStarted'),
		};
	}

	/** The STATUS of a state of Exchange (« waiting on others »: in process for the Tasks app). */
	public static function vtodoStatus(string $status): string {
		return match ($status) {
			'Completed' => 'COMPLETED',
			'InProgress', 'WaitingOnOthers' => 'IN-PROCESS',
			'Deferred' => 'CANCELLED',
			default => 'NEEDS-ACTION',
		};
	}

	/** The UID of an Exchange task in the Tasks app: the same every time. */
	public static function uid(string $itemId): string {
		return 'webmail-task-' . substr(sha1($itemId), 0, 32);
	}

	/**
	 * The tasks of an Exchange list as one VCALENDAR (read by the Tasks app of Nextcloud).
	 * @param list<array<string, mixed>> $tasks as EwsTasks::list() gives them
	 * @param string $link the address of the webmail ending with « task= », to open one there
	 */
	public static function calendar(array $tasks, string $link = '', ?\DateTimeImmutable $now = null): string {
		$utc = new \DateTimeZone('UTC');
		$stamp = ($now ?? new \DateTimeImmutable('now', $utc))->setTimezone($utc)->format('Ymd\THis\Z');
		$out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Webmail Exchange//Nextcloud//FR\r\n";
		foreach ($tasks as $t) {
			$id = (string)($t['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$lines = ['BEGIN:VTODO', 'UID:' . self::uid($id), 'DTSTAMP:' . $stamp, 'SUMMARY:' . self::text((string)($t['subject'] ?? ''))];
			if (!empty($t['due']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$t['due'])) {
				$lines[] = 'DUE;VALUE=DATE:' . str_replace('-', '', (string)$t['due']);
			}
			$status = (string)($t['status'] ?? 'NotStarted');
			$lines[] = 'STATUS:' . self::vtodoStatus($status);
			$percent = $status === 'Completed' ? 100 : max(0, min(100, (int)($t['percent'] ?? 0)));
			if ($percent > 0) {
				$lines[] = 'PERCENT-COMPLETE:' . $percent;
			}
			if ($status === 'Completed' && !empty($t['completed'])) {
				try {
					$lines[] = 'COMPLETED:' . (new \DateTimeImmutable((string)$t['completed']))->setTimezone($utc)->format('Ymd\THis\Z');
				} catch (\Exception) {
					// a date Exchange did not write well: left out
				}
			}
			$priority = self::priority((string)($t['importance'] ?? 'Normal'));
			if ($priority > 0) {
				$lines[] = 'PRIORITY:' . $priority;
			}
			$categories = array_filter(array_map(fn ($c) => self::text((string)$c, true), (array)($t['categories'] ?? [])), fn ($c) => $c !== '');
			if ($categories !== []) {
				$lines[] = 'CATEGORIES:' . implode(',', $categories);
			}
			if ($link !== '') {
				$lines[] = 'URL:' . $link . rawurlencode($id);
			}
			$lines[] = 'END:VTODO';
			foreach ($lines as $l) {
				$out .= self::fold($l) . "\r\n";
			}
		}
		return $out . "END:VCALENDAR\r\n";
	}

	/** A text value (RFC 5545 3.3.11): « \ ; , » and new lines escaped. */
	private static function text(string $s, bool $inList = false): string {
		$s = str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', ''], $s);
		return $inList ? trim($s) : $s;
	}

	/** Lines of at most 75 bytes, cut between UTF-8 characters. */
	private static function fold(string $line): string {
		$out = '';
		$len = 0;
		foreach (mb_str_split($line) as $ch) {
			if ($len + strlen($ch) > 75) {
				$out .= "\r\n ";
				$len = 1;
			}
			$out .= $ch;
			$len += strlen($ch);
		}
		return $out;
	}
}
