<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * Contacts to and from vCard files (.vcf), as exchanged with Outlook,
 * Nextcloud Contacts, phones… Written in vCard 3.0; read in 2.1, 3.0 and 4.0.
 * Contacts use the fields of EwsContacts::normalize().
 */
class VCard {
	private const PHONE_TYPES = ['mobilePhone' => 'CELL', 'businessPhone' => 'WORK', 'homePhone' => 'HOME'];

	/** The name of an exported file (without « .vcf »): the book's, without what a file name cannot have. */
	public static function fileName(string $name): string {
		$name = trim((string)preg_replace(['~[\\\\/:*?"<>|\x00-\x1f]+~', '~\s+~u'], ' ', $name));
		return $name !== '' ? mb_substr($name, 0, 100) : 'Contacts';
	}

	/** @param array<int, array<string, mixed>> $contacts */
	public static function export(array $contacts): string {
		$out = '';
		foreach ($contacts as $c) {
			$lines = ['BEGIN:VCARD', 'VERSION:3.0'];
			$name = trim((string)($c['displayName'] ?? ''));
			$lines[] = 'FN:' . self::esc($name !== '' ? $name : trim(($c['givenName'] ?? '') . ' ' . ($c['surname'] ?? '')));
			$lines[] = 'N:' . self::esc((string)($c['surname'] ?? '')) . ';' . self::esc((string)($c['givenName'] ?? '')) . ';;;';
			if (($c['company'] ?? '') !== '' || ($c['department'] ?? '') !== '') {
				$lines[] = 'ORG:' . self::esc((string)($c['company'] ?? '')) . (($c['department'] ?? '') !== '' ? ';' . self::esc((string)$c['department']) : '');
			}
			if (($c['jobTitle'] ?? '') !== '') {
				$lines[] = 'TITLE:' . self::esc((string)$c['jobTitle']);
			}
			foreach ((array)($c['emails'] ?? []) as $i => $e) {
				$lines[] = 'EMAIL;TYPE=INTERNET' . ($i === 0 ? ',PREF' : '') . ':' . self::esc((string)$e);
			}
			foreach (self::PHONE_TYPES as $key => $type) {
				if (($c[$key] ?? '') !== '') {
					$lines[] = 'TEL;TYPE=' . $type . ($type === 'CELL' ? '' : ',VOICE') . ':' . self::esc((string)$c[$key]);
				}
			}
			if (($c['street'] ?? '') !== '' || ($c['city'] ?? '') !== '' || ($c['postalCode'] ?? '') !== '' || ($c['country'] ?? '') !== '') {
				$lines[] = 'ADR;TYPE=WORK:;;' . self::esc((string)($c['street'] ?? '')) . ';' . self::esc((string)($c['city'] ?? '')) . ';;'
					. self::esc((string)($c['postalCode'] ?? '')) . ';' . self::esc((string)($c['country'] ?? ''));
			}
			if (($c['matrix'] ?? '') !== '') {
				// the matrix: URI scheme (« matrix:u/alice:example.org »), read by Element and other apps
				$lines[] = 'IMPP;X-SERVICE-TYPE=Matrix:matrix:u/' . self::esc(substr((string)$c['matrix'], 1));
			}
			if (($c['birthday'] ?? '') !== '') {
				// a birthday without a year (Outlook's 1604): « --0512 » (RFC 6350)
				$b = (string)$c['birthday'];
				$lines[] = str_starts_with($b, \OCA\Webmail\Ews\EwsContacts::NO_YEAR . '-')
					? 'BDAY;VALUE=date:--' . substr($b, 5, 2) . substr($b, 8, 2)
					: 'BDAY;VALUE=date:' . str_replace('-', '', $b);
			}
			if (trim((string)($c['notes'] ?? '')) !== '') {
				$lines[] = 'NOTE:' . self::esc(trim((string)$c['notes']));
			}
			$lines[] = 'END:VCARD';
			foreach ($lines as $l) {
				$out .= self::fold($l) . "\r\n";
			}
		}
		return $out;
	}

	/**
	 * The contacts of a .vcf file (one or many cards). Groups (KIND:group)
	 * and cards without any name, company or e-mail are skipped.
	 * @return list<array<string, mixed>>
	 */
	public static function parse(string $data): array {
		if (str_starts_with($data, "\xEF\xBB\xBF")) {
			$data = substr($data, 3);
		}
		if (!mb_check_encoding($data, 'UTF-8')) {
			$data = mb_convert_encoding($data, 'UTF-8', 'Windows-1252');
		}
		$data = str_replace(["\r\n", "\r"], "\n", $data);
		// vCard 2.1 quoted-printable soft line breaks, then folded lines
		$data = preg_replace('/=\n/', '', $data) ?? $data;
		$data = preg_replace('/\n[ \t]/', '', $data) ?? $data;

		$contacts = [];
		$card = null;
		foreach (explode("\n", $data) as $line) {
			if (trim($line) === '') {
				continue;
			}
			$colon = self::valueStart($line);
			if ($colon === null) {
				continue;
			}
			$head = substr($line, 0, $colon);
			$value = substr($line, $colon + 1);
			$parts = explode(';', $head);
			$name = strtoupper(preg_replace('/^.*\./', '', array_shift($parts)) ?? '');
			$params = [];
			$types = [];
			foreach ($parts as $p) {
				[$k, $v] = array_pad(explode('=', $p, 2), 2, null);
				if ($v === null) {
					$types[] = strtoupper($k); // vCard 2.1: TEL;CELL;VOICE
					continue;
				}
				$params[strtoupper($k)] = $v;
				if (strtoupper($k) === 'TYPE') {
					foreach (explode(',', trim($v, '"')) as $t) {
						$types[] = strtoupper($t);
					}
				}
			}
			if ($name === 'BEGIN' && strtoupper(trim($value)) === 'VCARD') {
				$card = ['emails' => [], 'phones' => [], 'adr' => null, 'group' => false];
				continue;
			}
			if ($card === null) {
				continue;
			}
			if ($name === 'END') {
				$c = self::toContact($card);
				if ($c !== null) {
					$contacts[] = $c;
				}
				$card = null;
				continue;
			}
			if (strtoupper($params['ENCODING'] ?? '') === 'QUOTED-PRINTABLE' || in_array('QUOTED-PRINTABLE', $types, true)) {
				$value = quoted_printable_decode($value);
				$charset = $params['CHARSET'] ?? 'UTF-8';
				if (strtoupper($charset) !== 'UTF-8') {
					$value = mb_convert_encoding($value, 'UTF-8', $charset);
				}
			}
			switch ($name) {
				case 'FN':
					$card['fn'] = self::unesc($value);
					break;
				case 'N':
					$n = self::split($value);
					$card['surname'] = $n[0] ?? '';
					$card['givenName'] = $n[1] ?? '';
					break;
				case 'ORG':
					$o = self::split($value);
					$card['company'] = $o[0] ?? '';
					$card['department'] = $o[1] ?? '';
					break;
				case 'TITLE':
					$card['jobTitle'] = self::unesc($value);
					break;
				case 'EMAIL':
					$e = trim(self::unesc(preg_replace('/^mailto:/i', '', $value) ?? $value));
					if ($e !== '') {
						in_array('PREF', $types, true) || isset($params['PREF']) ? array_unshift($card['emails'], $e) : $card['emails'][] = $e;
					}
					break;
				case 'TEL':
					$card['phones'][] = [trim(self::unesc(preg_replace('/^tel:/i', '', $value) ?? $value)), $types];
					break;
				case 'ADR':
					if ($card['adr'] === null || in_array('WORK', $types, true)) {
						$card['adr'] = self::split($value);
					}
					break;
				case 'NOTE':
					$card['notes'] = self::unesc($value);
					break;
				case 'IMPP':
				case 'X-MATRIX':
					$id = \OCA\Webmail\Ews\EwsContacts::matrixId(self::unesc($value));
					if ($id !== null && ($card['matrix'] ?? '') === '') {
						$card['matrix'] = $id;
					}
					break;
				case 'BDAY':
					$card['birthday'] = self::birthday(trim($value));
					break;
				case 'KIND':
				case 'X-ADDRESSBOOKSERVER-KIND':
					$card['group'] = strtolower(trim($value)) === 'group';
					break;
			}
		}
		return $contacts;
	}

	/**
	 * BDAY: « 19800512 », « 1980-05-12 », « 1980-05-12T00:00:00Z », or without a year « --0512 ».
	 * Anything else (text, impossible dates): left out.
	 */
	private static function birthday(string $v): string {
		if (preg_match('/^--(\d{2})-?(\d{2})$/', $v, $m)) {
			$date = \OCA\Webmail\Ews\EwsContacts::NO_YEAR . '-' . $m[1] . '-' . $m[2];
		} elseif (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})(T.*)?$/', $v, $m)) {
			$date = $m[1] . '-' . $m[2] . '-' . $m[3];
		} else {
			return '';
		}
		return \OCA\Webmail\Ews\EwsContacts::birthday($date) ?? '';
	}

	/** @param array<string, mixed> $card */
	private static function toContact(array $card): ?array {
		if ($card['group']) {
			return null;
		}
		$c = [
			'displayName' => trim((string)($card['fn'] ?? '')),
			'givenName' => trim((string)($card['givenName'] ?? '')),
			'surname' => trim((string)($card['surname'] ?? '')),
			'company' => trim((string)($card['company'] ?? '')),
			'department' => trim((string)($card['department'] ?? '')),
			'jobTitle' => trim((string)($card['jobTitle'] ?? '')),
			'emails' => array_slice(array_values(array_unique(array_filter($card['emails'], fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false))), 0, 3),
			'mobilePhone' => '', 'businessPhone' => '', 'homePhone' => '',
			'street' => '', 'city' => '', 'postalCode' => '', 'country' => '',
			'notes' => (string)($card['notes'] ?? ''),
			'matrix' => (string)($card['matrix'] ?? ''),
			'birthday' => (string)($card['birthday'] ?? ''),
		];
		$rest = [];
		foreach ($card['phones'] as [$number, $types]) {
			if ($number === '') {
				continue;
			}
			$key = match (true) {
				in_array('CELL', $types, true) || in_array('MOBILE', $types, true) => 'mobilePhone',
				in_array('WORK', $types, true) => 'businessPhone',
				in_array('HOME', $types, true) => 'homePhone',
				default => null,
			};
			if ($key !== null && $c[$key] === '') {
				$c[$key] = $number;
			} else {
				$rest[] = $number;
			}
		}
		foreach ($rest as $number) {
			foreach (['mobilePhone', 'businessPhone', 'homePhone'] as $key) {
				if ($c[$key] === '') {
					$c[$key] = $number;
					break;
				}
			}
		}
		if ($card['adr'] !== null) {
			// post office box; extended; street; city; region; postal code; country
			$a = $card['adr'];
			$c['street'] = trim(implode("\n", array_filter([trim($a[2] ?? ''), trim($a[1] ?? '')])));
			$c['city'] = trim($a[3] ?? '');
			$c['postalCode'] = trim($a[5] ?? '');
			$c['country'] = trim($a[6] ?? '');
		}
		if ($c['displayName'] === '' && $c['givenName'] === '' && $c['surname'] === '' && $c['company'] === '' && $c['emails'] === []) {
			return null;
		}
		return $c;
	}

	/** Position of the ':' that starts the value (not one inside a quoted parameter). */
	private static function valueStart(string $line): ?int {
		$quoted = false;
		for ($i = 0, $n = strlen($line); $i < $n; $i++) {
			if ($line[$i] === '"') {
				$quoted = !$quoted;
			} elseif ($line[$i] === ':' && !$quoted) {
				return $i;
			}
		}
		return null;
	}

	/** @return string[] the components of a structured value (N, ORG, ADR) */
	private static function split(string $value): array {
		$parts = preg_split('/(?<!\\\\);/', $value) ?: [];
		return array_map(fn ($p) => self::unesc($p), $parts);
	}

	private static function esc(string $s): string {
		return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $s);
	}

	private static function unesc(string $s): string {
		return strtr($s, ['\\n' => "\n", '\\N' => "\n", '\\,' => ',', '\\;' => ';', '\\\\' => '\\']);
	}

	/** Lines of at most 75 bytes, cut between UTF-8 characters. */
	private static function fold(string $line): string {
		$out = '';
		$len = 0;
		foreach (mb_str_split($line) as $ch) {
			if ($len + strlen($ch) > 75) {
				$out .= "\r\n ";
				$len = 1;
			}
			$out .= $ch;
			$len += strlen($ch);
		}
		return $out;
	}
}
