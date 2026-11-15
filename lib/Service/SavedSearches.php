<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Recherches enregistrées »: a search (its words, filters and folder) kept under a
 * name, offered in the side pane with the number of unread messages it finds.
 * Pure list handling: kept as JSON in a preference of the user, per account.
 */
class SavedSearches {
	public const MAX = 20;
	public const SINCE = ['', '7', '30', '365'];

	/** @return list<array<string, mixed>> the searches kept (bad entries left out) */
	public static function decode(string $json): array {
		$v = json_decode($json, true);
		if (!is_array($v)) {
			return [];
		}
		$out = [];
		foreach ($v as $s) {
			if (is_array($s) && ($n = self::normalize($s)) !== null && isset($s['id']) && is_string($s['id']) && preg_match('/^[a-z0-9]{8,32}$/', $s['id'])) {
				$out[] = ['id' => $s['id']] + $n;
			}
		}
		return array_slice($out, 0, self::MAX);
	}

	/**
	 * A search as kept, or null when it finds nothing particular (no words, no filter).
	 * @return array<string, mixed>|null
	 */
	public static function normalize(array $s): ?array {
		$since = (string)($s['since'] ?? '');
		$n = [
			'name' => mb_substr(trim((string)($s['name'] ?? '')), 0, 80),
			'q' => mb_substr(trim((string)($s['q'] ?? '')), 0, 500),
			'folder' => mb_substr(trim((string)($s['folder'] ?? '')), 0, 512) ?: 'inbox',
			'unread' => !empty($s['unread']),
			'attachments' => !empty($s['attachments']),
			'flagged' => !empty($s['flagged']),
			'since' => in_array($since, self::SINCE, true) ? $since : '',
			'all' => !empty($s['all']),
		];
		if ($n['q'] === '' && !$n['unread'] && !$n['attachments'] && !$n['flagged'] && $n['since'] === '') {
			return null;
		}
		if ($n['name'] === '') {
			$n['name'] = $n['q'] !== '' ? mb_substr($n['q'], 0, 80) : 'Recherche';
		}
		return $n;
	}

	/**
	 * The list with $search added (a search with the same name replaced, in its place).
	 * @param list<array<string, mixed>> $list
	 * @return list<array<string, mixed>>
	 * @throws \InvalidArgumentException a search finding nothing particular, or too many
	 */
	public static function add(array $list, array $search, string $id): array {
		$n = self::normalize($search);
		if ($n === null) {
			throw new \InvalidArgumentException('empty search');
		}
		foreach ($list as $i => $s) {
			if (mb_strtolower((string)$s['name']) === mb_strtolower($n['name'])) {
				$list[$i] = ['id' => $s['id']] + $n;
				return $list;
			}
		}
		if (count($list) >= self::MAX) {
			throw new \InvalidArgumentException('too many');
		}
		$list[] = ['id' => $id] + $n;
		return $list;
	}

	/** @param list<array<string, mixed>> $list @return list<array<string, mixed>> */
	public static function remove(array $list, string $id): array {
		return array_values(array_filter($list, fn ($s) => $s['id'] !== $id));
	}

	/** The filters of the list request for a search, its unread messages only (the counter). */
	public static function filters(array $s, bool $unreadOnly): array {
		return [
			'unread' => $unreadOnly || !empty($s['unread']),
			'attachments' => !empty($s['attachments']),
			'flagged' => !empty($s['flagged']),
			'since' => ($s['since'] ?? '') !== '' ? (new \DateTimeImmutable('today'))->modify('-' . (int)$s['since'] . ' days')->format('Y-m-d') : '',
		];
	}
}
