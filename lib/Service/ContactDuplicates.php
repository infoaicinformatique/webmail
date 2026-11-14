<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Doublons »: the contacts of a book that are the same person (an address
 * in common, or the same name), and the card that results from merging them.
 * Nothing is lost: what does not fit in the card kept goes into its notes.
 * Pure functions, so the rules are tested without Exchange.
 */
class ContactDuplicates {
	/** the fields of one value: the card kept keeps its own, else the first found */
	public const FIELDS = ['displayName', 'givenName', 'surname', 'company', 'department', 'jobTitle',
		'mobilePhone', 'businessPhone', 'homePhone', 'street', 'city', 'postalCode', 'country', 'birthday', 'matrix'];
	public const PHONES = ['mobilePhone', 'businessPhone', 'homePhone'];
	/** Exchange keeps three addresses per contact */
	public const MAX_EMAILS = 3;

	public static function fold(string $s): string {
		$s = mb_strtolower(trim($s));
		$t = class_exists(\Normalizer::class) ? (string)\Normalizer::normalize($s, \Normalizer::FORM_D) : $s;
		$t = (string)preg_replace('/\p{Mn}+/u', '', $t);
		return trim((string)preg_replace('/[\s.,;\'’-]+/u', ' ', $t));
	}

	/** The name that identifies a person: « Jean Dupont » = « DUPONT Jean » (two words at least). */
	public static function nameKey(array $c): string {
		$name = trim((string)($c['displayName'] ?? ''));
		if ($name === '' || str_contains($name, '@')) {
			$name = trim(($c['givenName'] ?? '') . ' ' . ($c['surname'] ?? ''));
		}
		$words = array_values(array_filter(explode(' ', self::fold($name)), fn ($w) => $w !== ''));
		if (count($words) < 2) {
			return '';
		}
		sort($words);
		return implode(' ', $words);
	}

	/**
	 * The groups of duplicates (two contacts or more each), by name.
	 * @param list<array<string, mixed>> $contacts with their « id »
	 * @return list<list<array<string, mixed>>>
	 */
	public static function groups(array $contacts): array {
		$parent = [];
		$find = function (int $i) use (&$parent, &$find): int {
			return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i]));
		};
		$byKey = [];
		foreach ($contacts as $i => $c) {
			$parent[$i] = $i;
			$keys = [];
			foreach ((array)($c['emails'] ?? []) as $e) {
				$e = mb_strtolower(trim((string)$e));
				if ($e !== '') {
					$keys[] = 'e:' . $e;
				}
			}
			$n = self::nameKey($c);
			if ($n !== '') {
				$keys[] = 'n:' . $n;
			}
			foreach ($keys as $k) {
				if (isset($byKey[$k])) {
					$parent[$find($i)] = $find($byKey[$k]);
				} else {
					$byKey[$k] = $i;
				}
			}
		}
		$groups = [];
		foreach ($contacts as $i => $c) {
			$groups[$find($i)][] = $c;
		}
		$groups = array_values(array_filter($groups, fn ($g) => count($g) > 1));
		usort($groups, fn ($a, $b) => strcasecmp((string)($a[0]['displayName'] ?? ''), (string)($b[0]['displayName'] ?? '')));
		return $groups;
	}

	/**
	 * The card kept, completed by the others.
	 * @param array<string, mixed> $keep
	 * @param list<array<string, mixed>> $others
	 * @return array<string, mixed>
	 */
	public static function merge(array $keep, array $others): array {
		$out = $keep;
		$extra = [];
		foreach (self::FIELDS as $f) {
			$out[$f] = trim((string)($keep[$f] ?? ''));
			foreach ($others as $o) {
				$v = trim((string)($o[$f] ?? ''));
				if ($v === '') {
					continue;
				}
				if ($out[$f] === '') {
					$out[$f] = $v;
				} elseif (in_array($f, self::PHONES, true) && self::digits($v) !== self::digits($out[$f])) {
					$extra[] = $v;
				}
			}
		}
		// the addresses: those of the card kept first, each once
		$emails = [];
		foreach (array_merge([$keep], $others) as $c) {
			foreach ((array)($c['emails'] ?? []) as $e) {
				$e = trim((string)$e);
				if ($e !== '' && !in_array(mb_strtolower($e), array_map('mb_strtolower', $emails), true)) {
					$emails[] = $e;
				}
			}
		}
		$out['emails'] = array_slice($emails, 0, self::MAX_EMAILS);
		$moreEmails = array_slice($emails, self::MAX_EMAILS);
		// the phone numbers already somewhere on the card are not repeated
		$onCard = array_map(fn ($f) => self::digits((string)$out[$f]), self::PHONES);
		$extra = array_values(array_unique(array_filter($extra, fn ($p) => !in_array(self::digits($p), $onCard, true))));
		$notes = [];
		foreach (array_merge([$keep], $others) as $c) {
			$n = trim((string)($c['notes'] ?? ''));
			if ($n !== '' && !in_array($n, $notes, true)) {
				$notes[] = $n;
			}
		}
		if ($moreEmails !== []) {
			$notes[] = 'Autres adresses : ' . implode(', ', $moreEmails);
		}
		if ($extra !== []) {
			$notes[] = 'Autres numéros : ' . implode(', ', $extra);
		}
		$out['notes'] = implode("\n\n", $notes);
		return $out;
	}

	private static function digits(string $phone): string {
		$d = (string)preg_replace('/\D+/', '', $phone);
		// +33 6… = 06…
		return str_starts_with($d, '33') && strlen($d) === 11 ? '0' . substr($d, 2) : $d;
	}
}
