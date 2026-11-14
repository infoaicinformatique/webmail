<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Exception\MailException;

/**
 * The messages pinned at the top of their folder (« Épingler »), kept in the
 * user's preferences as a list of Exchange ids, the last pinned first.
 * Pure functions, so the rules are tested without Nextcloud.
 */
class PinList {
	/** At most this many pinned messages per account: a few, or it is a folder. */
	public const MAX = 20;

	/** @return list<string> */
	public static function decode(string $json): array {
		$list = json_decode($json, true);
		if (!is_array($list)) {
			return [];
		}
		return array_values(array_unique(array_filter($list, fn ($id) => is_string($id) && $id !== '')));
	}

	/**
	 * @param list<string> $list
	 * @return list<string>
	 * @throws MailException
	 */
	public static function set(array $list, string $id, bool $pinned): array {
		if ($id === '' || strlen($id) > 1024) {
			throw new MailException('Message invalide');
		}
		$list = array_values(array_filter($list, fn ($x) => $x !== $id));
		if (!$pinned) {
			return $list;
		}
		if (count($list) >= self::MAX) {
			throw new MailException('%s messages épinglés au plus : désépinglez-en un d\'abord', [(string)self::MAX]);
		}
		array_unshift($list, $id);
		return $list;
	}

	/**
	 * The list without the messages gone (deleted, or moved: Exchange gives them another id).
	 * @param list<string> $list
	 * @param list<string> $found
	 * @return list<string>
	 */
	public static function keep(array $list, array $found): array {
		$found = array_flip($found);
		return array_values(array_filter($list, fn ($id) => isset($found[$id])));
	}
}
