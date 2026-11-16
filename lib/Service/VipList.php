<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Expéditeurs prioritaires »: the addresses whose messages stand out (a star in the list,
 * « ⭐ Prioritaires » in the side pane, a notification even when the others are off). Pure.
 */
class VipList {
	public const MAX = 200;
	public const PREF = 'vip_';

	/** @return list<string> */
	public static function decode(string $json): array {
		$v = json_decode($json, true);
		if (!is_array($v)) {
			return [];
		}
		$out = [];
		foreach ($v as $e) {
			$e = is_string($e) ? mb_strtolower(trim($e)) : '';
			if (filter_var($e, FILTER_VALIDATE_EMAIL) && !in_array($e, $out, true)) {
				$out[] = $e;
			}
		}
		return array_slice($out, 0, self::MAX);
	}

	/** @param list<string> $list @return list<string> */
	public static function set(array $list, string $email, bool $vip): array {
		$email = mb_strtolower(trim($email));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new \InvalidArgumentException('address');
		}
		$list = array_values(array_filter($list, fn ($e) => $e !== $email));
		if ($vip) {
			if (count($list) >= self::MAX) {
				throw new \InvalidArgumentException('too many');
			}
			$list[] = $email;
		}
		return $list;
	}

	public static function has(array $list, ?array $from): bool {
		return is_array($from) && in_array(mb_strtolower((string)($from['email'] ?? '')), $list, true);
	}
}
