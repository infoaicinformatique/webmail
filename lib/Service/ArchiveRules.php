<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Archiver »: which folder is the archive, and which messages the nightly
 * archiving takes. Pure: no Exchange, no Nextcloud.
 */
class ArchiveRules {
	/** the archiving of the old read messages: after this many months (0: off) */
	public const MONTHS = [0, 3, 6, 12, 24];
	/** names of a folder taken as the archive (at the top of the mailbox) */
	public const NAMES = ['archives', 'archive', 'archiv', 'archivio', 'archivo'];
	/** most messages archived per account and night */
	public const PER_NIGHT = 500;

	/**
	 * The archive among the folders: one at the top named « Archives » (or « Archive »…), else null.
	 * @param list<array{id: string, name: string, parentId: ?string, role?: ?string}> $folders
	 */
	public static function archiveFolder(array $folders): ?string {
		foreach ($folders as $f) {
			if (($f['role'] ?? null) === 'archive') {
				return (string)$f['id'];
			}
		}
		foreach ($folders as $f) {
			if (($f['parentId'] ?? null) === null && empty($f['role'])
				&& in_array(mb_strtolower(trim((string)$f['name'])), self::NAMES, true)) {
				return (string)$f['id'];
			}
		}
		return null;
	}

	public static function months(int $months): int {
		return in_array($months, self::MONTHS, true) ? $months : 0;
	}

	/** The day before which the read messages are archived. */
	public static function cutoff(int $months, \DateTimeImmutable $now): \DateTimeImmutable {
		return $now->setTime(0, 0)->modify('-' . $months . ' months');
	}

	/**
	 * The messages to archive, from a page of the inbox (oldest first): read, not
	 * followed (flag), not pinned, received before $cutoff.
	 * @param list<array<string, mixed>> $items
	 * @param list<string> $pinned
	 * @return array{ids: list<string>, done: bool} done: a message after $cutoff was reached
	 */
	public static function archivable(array $items, \DateTimeImmutable $cutoff, array $pinned): array {
		$ids = [];
		foreach ($items as $m) {
			$date = (string)($m['date'] ?? '');
			try {
				$at = new \DateTimeImmutable($date);
			} catch (\Exception) {
				continue;
			}
			if ($date === '' || $at >= $cutoff) {
				return ['ids' => $ids, 'done' => true];
			}
			if (empty($m['isRead']) || !empty($m['flagged']) || in_array($m['id'], $pinned, true)) {
				continue;
			}
			$ids[] = (string)$m['id'];
		}
		return ['ids' => $ids, 'done' => false];
	}
}
