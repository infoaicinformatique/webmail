<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Exception\MailException;

/**
 * The other signatures of an account (« Courte », « Anglais »…), beside its
 * main one, and the one each kind of message starts with: « » the main
 * signature, « - » none, else the name of another. Pure functions.
 */
class SignatureSet {
	public const MAX = 10;
	public const NAME_MAX = 40;
	public const MAIN = '';
	public const NONE = '-';

	/** @return array{list: list<array{name: string, html: string}>, new: string, reply: string} */
	public static function decode(string $json): array {
		$d = json_decode($json, true);
		if (!is_array($d)) {
			return ['list' => [], 'new' => self::MAIN, 'reply' => self::MAIN];
		}
		try {
			return self::normalize((array)($d['list'] ?? []), (string)($d['new'] ?? ''), (string)($d['reply'] ?? ''), fn ($h) => $h);
		} catch (MailException $e) {
			return ['list' => [], 'new' => self::MAIN, 'reply' => self::MAIN];
		}
	}

	/**
	 * @param array<int, mixed> $list
	 * @param callable(string): string $sanitize
	 * @return array{list: list<array{name: string, html: string}>, new: string, reply: string}
	 * @throws MailException
	 */
	public static function normalize(array $list, string $new, string $reply, callable $sanitize): array {
		$out = [];
		$names = [];
		foreach ($list as $s) {
			if (!is_array($s)) {
				continue;
			}
			$name = trim((string)preg_replace('/\s+/u', ' ', (string)($s['name'] ?? '')));
			$html = $sanitize((string)($s['html'] ?? ''));
			if ($name === '' && trim(strip_tags($html)) === '') {
				continue;
			}
			if ($name === '' || $name === self::NONE) {
				throw new MailException('Donnez un nom à chaque signature');
			}
			if (mb_strlen($name) > self::NAME_MAX) {
				throw new MailException('Nom de signature trop long : %s', [$name]);
			}
			if (in_array(mb_strtolower($name), $names, true)) {
				throw new MailException('Deux signatures portent le nom « %s »', [$name]);
			}
			$names[] = mb_strtolower($name);
			$out[] = ['name' => $name, 'html' => $html];
		}
		if (count($out) > self::MAX) {
			throw new MailException('%s signatures au plus', [(string)self::MAX]);
		}
		$pick = function (string $v) use ($out): string {
			if ($v === self::MAIN || $v === self::NONE) {
				return $v;
			}
			foreach ($out as $s) {
				if (mb_strtolower($s['name']) === mb_strtolower($v)) {
					return $s['name'];
				}
			}
			// a signature removed: the main one again
			return self::MAIN;
		};
		return ['list' => $out, 'new' => $pick($new), 'reply' => $pick($reply)];
	}
}
