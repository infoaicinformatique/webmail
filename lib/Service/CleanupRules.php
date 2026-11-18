<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Nettoyage automatique »: folder by folder, the messages older than some days deleted each
 * night (to « Éléments supprimés »; for good from « Éléments supprimés » itself). The messages
 * followed (flag) or pinned are kept. Pure.
 */
class CleanupRules {
	public const PREF = 'cleanup_';
	public const DAYS = [0, 7, 14, 30, 60, 90, 180, 365];
	public const MAX_FOLDERS = 30;
	public const PER_NIGHT = 500;
	/** the folders that are never cleaned: what is being written or about to leave */
	public const NEVER = ['drafts', 'outbox'];

	/** @return array<string, int> folder id => days */
	public static function decode(string $json): array {
		$v = json_decode($json, true);
		$out = [];
		foreach (is_array($v) ? $v : [] as $folder => $days) {
			if (is_string($folder) && $folder !== '' && is_int($days) && $days > 0 && in_array($days, self::DAYS, true)) {
				$out[$folder] = $days;
			}
		}
		return array_slice($out, 0, self::MAX_FOLDERS, true);
	}

	/**
	 * @param array<string, int> $rules
	 * @return array<string, int>
	 * @throws \InvalidArgumentException
	 */
	public static function set(array $rules, string $folderId, int $days): array {
		if ($folderId === '' || !in_array($days, self::DAYS, true)) {
			throw new \InvalidArgumentException('days');
		}
		unset($rules[$folderId]);
		if ($days > 0) {
			if (count($rules) >= self::MAX_FOLDERS) {
				throw new \InvalidArgumentException('too many');
			}
			$rules[$folderId] = $days;
		}
		return $rules;
	}

	/** The day before which the messages go (midnight, UTC). */
	public static function cutoff(int $days, \DateTimeImmutable $now): \DateTimeImmutable {
		return $now->setTime(0, 0)->modify('-' . $days . ' days');
	}

	/**
	 * From a page of the folder (oldest first): the messages received before $cutoff,
	 * not followed, not pinned.
	 * @param list<array<string, mixed>> $items
	 * @param list<string> $pinned
	 * @return array{ids: list<string>, done: bool} done: a message after $cutoff was reached
	 */
	public static function deletable(array $items, \DateTimeImmutable $cutoff, array $pinned): array {
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
			if (!empty($m['flagged']) || in_array($m['id'], $pinned, true)) {
				continue;
			}
			$ids[] = (string)$m['id'];
		}
		return ['ids' => $ids, 'done' => false];
	}
}
