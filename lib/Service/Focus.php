<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Prioritaires / Autres »: the inbox in two tabs, the messages from people first,
 * the mailing lists, newsletters and automatic notifications aside. The user's choices
 * for a sender (« Toujours dans Prioritaires / Autres ») come first. Pure.
 */
class Focus {
	public const PREF = 'focus_';
	public const MAX = 500;

	/** the start of an address that a person does not use (a robot, a list) */
	private const AUTOMATED = '/^(no[-_.]?reply|do[-_.]?not[-_.]?reply|ne[-_.]?pas[-_.]?repondre|nepasrepondre|notifications?|notify|alerts?|newsletters?|news|info[-_.]?lettre|mailer[-_.]?daemon|postmaster|bounces?|marketing|promo(tions?)?|offres?|mailing|campaigns?|automated|robot)([-_.+].*)?@/';

	public static function automated(?string $email): bool {
		return is_string($email) && preg_match(self::AUTOMATED, mb_strtolower(trim($email))) === 1;
	}

	/** @return array{priority: list<string>, other: list<string>} */
	public static function decode(string $json): array {
		$v = json_decode($json, true);
		$out = ['priority' => [], 'other' => []];
		if (!is_array($v)) {
			return $out;
		}
		foreach (['priority', 'other'] as $k) {
			foreach (is_array($v[$k] ?? null) ? $v[$k] : [] as $e) {
				$e = is_string($e) ? mb_strtolower(trim($e)) : '';
				if (filter_var($e, FILTER_VALIDATE_EMAIL) && !in_array($e, $out['priority'], true) && !in_array($e, $out['other'], true)) {
					$out[$k][] = $e;
				}
			}
		}
		$out['priority'] = array_slice($out['priority'], 0, self::MAX);
		$out['other'] = array_slice($out['other'], 0, self::MAX);
		return $out;
	}

	/**
	 * A sender put in a tab for good ('priority', 'other'), or back to the automatic choice ('').
	 * @param array{priority: list<string>, other: list<string>} $o
	 * @return array{priority: list<string>, other: list<string>}
	 */
	public static function set(array $o, string $email, string $where): array {
		$email = mb_strtolower(trim($email));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new \InvalidArgumentException('address');
		}
		if (!in_array($where, ['priority', 'other', ''], true)) {
			throw new \InvalidArgumentException('where');
		}
		foreach (['priority', 'other'] as $k) {
			$o[$k] = array_values(array_filter($o[$k] ?? [], fn ($e) => $e !== $email));
		}
		if ($where !== '') {
			if (count($o[$where]) >= self::MAX) {
				throw new \InvalidArgumentException('too many');
			}
			$o[$where][] = $email;
		}
		return $o;
	}

	/**
	 * In « Autres »: a mailing list or an automatic sender, unless the user said otherwise
	 * or the sender is a priority one.
	 * @param array<string, mixed> $item
	 * @param list<string> $vip
	 * @param array{priority: list<string>, other: list<string>} $o
	 */
	public static function isOther(array $item, array $vip, array $o): bool {
		$email = mb_strtolower(trim((string)($item['from']['email'] ?? '')));
		if ($email !== '' && in_array($email, $o['priority'], true)) {
			return false;
		}
		if ($email !== '' && in_array($email, $o['other'], true)) {
			return true;
		}
		if (VipList::has($vip, $item['from'] ?? null)) {
			return false;
		}
		return !empty($item['isList']) || self::automated($email);
	}
}
