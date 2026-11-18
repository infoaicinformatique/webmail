<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Sauvegarde dans Fichiers »: each new message of the folders chosen kept as a .eml in
 * Nextcloud Files (by year and month), by a background job. Where it got to is a date and the
 * messages saved at that very date (several can share it). Pure.
 */
class Backup {
	public const PREF = 'backup_';
	public const FOLDERS = ['inbox' => 'Reçus', 'sentitems' => 'Envoyés'];
	/** from when the messages are saved: now, or some days before */
	public const FROM = [0, 30, 365];
	public const DEFAULT_PATH = 'Webmail/Sauvegarde';
	/** messages saved by one run of the job (the rest at the next one) */
	public const PER_RUN = 200;

	/**
	 * @return array{on: bool, path: string, folders: list<string>, cursors: array<string, array{at: string, ids: list<string>}>, last: array{at: string, saved: int, error: string}, total: int}
	 */
	public static function decode(string $json): array {
		$v = json_decode($json, true);
		$v = is_array($v) ? $v : [];
		$folders = array_values(array_intersect(array_keys(self::FOLDERS), is_array($v['folders'] ?? null) ? $v['folders'] : []));
		$cursors = [];
		foreach (is_array($v['cursors'] ?? null) ? $v['cursors'] : [] as $k => $c) {
			if (isset(self::FOLDERS[$k]) && is_array($c) && is_string($c['at'] ?? null)) {
				$cursors[$k] = ['at' => $c['at'], 'ids' => array_values(array_filter((array)($c['ids'] ?? []), 'is_string'))];
			}
		}
		$last = is_array($v['last'] ?? null) ? $v['last'] : [];
		return [
			'on' => !empty($v['on']) && $folders !== [],
			'path' => is_string($v['path'] ?? null) && self::validPath($v['path']) ? $v['path'] : self::DEFAULT_PATH,
			'folders' => $folders !== [] ? $folders : array_keys(self::FOLDERS),
			'cursors' => $cursors,
			'last' => ['at' => (string)($last['at'] ?? ''), 'saved' => (int)($last['saved'] ?? 0), 'error' => (string)($last['error'] ?? '')],
			'total' => (int)($v['total'] ?? 0),
		];
	}

	/** a folder of the user's Files, given from its root: « Documents/Courrier » */
	public static function normalizePath(string $path): string {
		$parts = array_filter(array_map('trim', explode('/', str_replace('\\', '/', $path))), fn ($p) => $p !== '' && $p !== '.');
		return implode('/', $parts);
	}

	public static function validPath(string $path): bool {
		$path = self::normalizePath($path);
		if ($path === '' || mb_strlen($path) > 250) {
			return false;
		}
		foreach (explode('/', $path) as $p) {
			if ($p === '..' || preg_match('/[\x00-\x1f*?"<>|:]/', $p) || str_starts_with($p, '.')) {
				return false;
			}
		}
		return true;
	}

	/** where the cursor starts: now, or $days before */
	public static function start(\DateTimeImmutable $now, int $days): string {
		$days = in_array($days, self::FROM, true) ? $days : 0;
		return $now->setTimezone(new \DateTimeZone('UTC'))->modify('-' . $days . ' days')->format('Y-m-d\TH:i:s\Z');
	}

	/**
	 * The messages still to save (oldest first): after the cursor, or at its date and not saved yet.
	 * @param list<array{id: string, subject: string, date: string}> $items
	 * @param array{at: string, ids: list<string>} $cursor
	 * @return list<array{id: string, subject: string, date: string}>
	 */
	public static function pending(array $items, array $cursor, int $max = self::PER_RUN): array {
		$at = new \DateTimeImmutable($cursor['at']);
		$out = array_values(array_filter($items, function ($m) use ($at, $cursor) {
			$d = new \DateTimeImmutable($m['date']);
			return $d > $at || ($d == $at && !in_array($m['id'], $cursor['ids'], true));
		}));
		usort($out, fn ($a, $b) => (new \DateTimeImmutable($a['date'])) <=> (new \DateTimeImmutable($b['date'])));
		return array_slice($out, 0, $max);
	}

	/**
	 * The cursor once a message is saved.
	 * @param array{at: string, ids: list<string>} $cursor
	 * @return array{at: string, ids: list<string>}
	 */
	public static function advance(array $cursor, string $id, string $date): array {
		$d = new \DateTimeImmutable($date);
		$at = new \DateTimeImmutable($cursor['at']);
		if ($d > $at) {
			return ['at' => $date, 'ids' => [$id]];
		}
		if ($d == $at && !in_array($id, $cursor['ids'], true)) {
			$cursor['ids'][] = $id;
		}
		return $cursor;
	}

	/** « Webmail/Sauvegarde/moi@societe.fr/Reçus/2026/2026-11 » */
	public static function folderFor(string $path, string $email, string $folder, string $date, \DateTimeZone $zone): string {
		$d = (new \DateTimeImmutable($date))->setTimezone($zone);
		return self::normalizePath($path) . '/' . AttachmentZip::safeName(mb_strtolower($email), 'compte') . '/' . (self::FOLDERS[$folder] ?? $folder)
			. '/' . $d->format('Y') . '/' . $d->format('Y-m');
	}
}
