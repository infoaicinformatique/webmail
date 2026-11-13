<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Exception\MailException;

/**
 * The messages put aside until a given time (« Reporter »), as kept in the user's preferences:
 * a list of {id: the message in the « Reportés » folder, until: UTC time}.
 * Pure functions, so the rules are tested without Nextcloud.
 */
class SnoozeList {
	/** At most this many messages put aside per account. */
	public const MAX = 500;

	/**
	 * The time a message comes back: in the future (a minute at least), within a year.
	 *
	 * @return string UTC, « 2026-11-14T07:00:00Z »
	 * @throws MailException
	 */
	public static function until(string $value, int $now): string {
		$t = $value !== '' ? strtotime($value) : false;
		if ($t === false || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) {
			throw new MailException('Date invalide');
		}
		if ($t < $now + 60) {
			throw new MailException('Choisissez une heure à venir');
		}
		if ($t > $now + 366 * 86400) {
			throw new MailException('Un an au plus');
		}
		return gmdate('Y-m-d\TH:i:s\Z', $t);
	}

	/** @return list<array{id: string, until: string}> */
	public static function decode(string $json): array {
		$list = json_decode($json, true);
		if (!is_array($list)) {
			return [];
		}
		$out = [];
		foreach ($list as $e) {
			if (is_array($e) && is_string($e['id'] ?? null) && $e['id'] !== '' && is_string($e['until'] ?? null)) {
				$out[] = ['id' => $e['id'], 'until' => $e['until']];
			}
		}
		return $out;
	}

	/**
	 * @param list<array{id: string, until: string}> $list
	 * @param string[] $ids
	 * @return list<array{id: string, until: string}> (a message put aside again keeps only its new time)
	 * @throws MailException
	 */
	public static function add(array $list, array $ids, string $until): array {
		$list = self::without($list, $ids);
		foreach ($ids as $id) {
			$list[] = ['id' => $id, 'until' => $until];
		}
		if (count($list) > self::MAX) {
			throw new MailException('Trop de messages reportés (%s au plus)', [(string)self::MAX]);
		}
		return $list;
	}

	/**
	 * @param list<array{id: string, until: string}> $list
	 * @param string[] $ids
	 * @return list<array{id: string, until: string}>
	 */
	public static function without(array $list, array $ids): array {
		return array_values(array_filter($list, fn ($e) => !in_array($e['id'], $ids, true)));
	}

	/**
	 * The messages whose time has come, and the others.
	 *
	 * @param list<array{id: string, until: string}> $list
	 * @return array{0: list<array{id: string, until: string}>, 1: list<array{id: string, until: string}>}
	 */
	public static function due(array $list, int $now): array {
		$due = [];
		$rest = [];
		foreach ($list as $e) {
			$t = strtotime($e['until']);
			if ($t === false || $t <= $now) {
				$due[] = $e;
			} else {
				$rest[] = $e;
			}
		}
		return [$due, $rest];
	}
}
