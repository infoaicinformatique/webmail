<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * The signs of a message to be wary of, shown above it: a sender from outside the
 * organisation, one borrowing the name of a colleague (« Paul Durand » <paul.durand@gmail.com>),
 * links whose text shows an address and that lead elsewhere (pure functions, tested without a server).
 */
final class SecurityCheck {
	/** The domain of an address, lower case ('' when it has none). */
	public static function domain(string $email): string {
		$at = strrpos($email, '@');
		return $at === false ? '' : strtolower(trim(substr($email, $at + 1), " \t>."));
	}

	/**
	 * From outside: its domain is none of the organisation's (nor one of their subdomains).
	 * @param string[] $own
	 */
	public static function isExternal(string $email, array $own): bool {
		$d = self::domain($email);
		if ($d === '' || $own === []) {
			return false;
		}
		foreach ($own as $o) {
			$o = strtolower(trim($o));
			if ($o !== '' && ($d === $o || str_ends_with($d, '.' . $o))) {
				return false;
			}
		}
		return true;
	}

	/** A name compared as people read it: no accents, no case, no punctuation, single spaces. */
	public static function normalizeName(string $name): string {
		$name = trim(preg_replace('/\s*[<(].*$/u', '', $name) ?? $name, " \t\"'");
		$ascii = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;
		$ascii = strtolower((string)preg_replace('/[^A-Za-z0-9@.]+/', ' ', $ascii !== '' ? $ascii : $name));
		return trim((string)preg_replace('/\s+/', ' ', $ascii));
	}

	/**
	 * An address of the organisation written in the name of an outside sender
	 * (« paul.durand@societe-a.fr » <x@gmail.com>): the address it pretends to be, or null.
	 * @param string[] $own
	 */
	public static function addressInName(string $name, string $email, array $own): ?string {
		if (!preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $name, $m)) {
			return null;
		}
		$shown = strtolower($m[0]);
		return $shown !== strtolower($email) && !self::isExternal($shown, $own) && self::isExternal($email, $own) ? $shown : null;
	}

	/** The host of a link, lower case, without « www. » ('' when it is no web link). */
	public static function host(string $url): string {
		$url = trim($url);
		if (!preg_match('~^https?://~i', $url)) {
			if (!preg_match('~^(?:www\.)?([a-z0-9-]+\.)+[a-z]{2,}(?:[/:?#]|$)~i', $url)) {
				return '';
			}
			$url = 'http://' . $url;
		}
		$host = strtolower((string)parse_url($url, PHP_URL_HOST));
		return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
	}

	/**
	 * A link whose text is an address (« www.ma-banque.fr », « https://… ») leading to another site:
	 * the host it really goes to, or null. A subdomain of the one shown (or the reverse) is the same site.
	 */
	public static function deceptive(string $text, string $href): ?string {
		$text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
		if ($text === '' || mb_strlen($text) > 200 || str_contains($text, ' ')) {
			return null;
		}
		$shown = self::host($text);
		$real = self::host($href);
		if ($shown === '' || $real === '' || !preg_match('~^https?://~i', trim($href))) {
			return null;
		}
		if ($shown === $real || str_ends_with($real, '.' . $shown) || str_ends_with($shown, '.' . $real)) {
			return null;
		}
		return $real;
	}
}
