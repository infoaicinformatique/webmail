<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Expéditeurs bloqués et autorisés »: entries (an address, or a whole domain written
 * « @domaine.fr ») and whether a sender matches one. Pure.
 */
class SenderLists {
	public const KINDS = ['blocked', 'allowed'];
	/** most entries in a list (they all go into one rule of Exchange) */
	public const MAX = 200;

	/** « Jean <jean@x.fr> », « x.fr », « @X.fr » → « jean@x.fr », « @x.fr », « @x.fr »; null when not an address nor a domain */
	public static function normalize(string $entry): ?string {
		$e = mb_strtolower(trim($entry));
		if (preg_match('/<([^>]+)>/', $e, $m)) {
			$e = trim($m[1]);
		}
		$e = trim($e, " \t\"'<>;,");
		// « *@domaine » is a domain, not an address
		if (!str_starts_with($e, '*@') && filter_var($e, FILTER_VALIDATE_EMAIL)) {
			return $e;
		}
		$domain = ltrim($e, '@*.');
		if (preg_match('/^(?=.{3,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
			return '@' . $domain;
		}
		return null;
	}

	/**
	 * @param iterable<string> $entries
	 * @return list<string> normalized, each once, in order
	 */
	public static function clean(iterable $entries): array {
		$out = [];
		foreach ($entries as $e) {
			$n = self::normalize((string)$e);
			if ($n !== null && !in_array($n, $out, true)) {
				$out[] = $n;
			}
		}
		return array_slice($out, 0, self::MAX);
	}

	/** Does $email match one of the entries (the address itself, or its domain or a parent domain)? */
	public static function matches(string $email, array $entries): bool {
		$email = mb_strtolower(trim($email));
		$at = strrpos($email, '@');
		if ($at === false) {
			return false;
		}
		$domain = substr($email, $at + 1);
		foreach ($entries as $e) {
			if ($e === $email) {
				return true;
			}
			if (str_starts_with($e, '@')) {
				$d = substr($e, 1);
				if ($domain === $d || str_ends_with($domain, '.' . $d)) {
					return true;
				}
			}
		}
		return false;
	}
}
