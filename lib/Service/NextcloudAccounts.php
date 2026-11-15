<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * An account of the system address book of Nextcloud (as the Contacts API gives it)
 * as a contact of the webmail. Tested without a server.
 */
final class NextcloudAccounts {
	/**
	 * @param array<string, mixed> $entry FN, N, EMAIL, TEL, ORG, TITLE, ADR, UID…
	 * @return ?array<string, mixed> null without a name nor an address
	 */
	public static function contact(array $entry): ?array {
		$list = function (string $key) use ($entry): array {
			$v = $entry[$key] ?? [];
			$out = [];
			foreach (is_array($v) ? $v : [$v] as $x) {
				$x = trim((string)(is_array($x) ? ($x['value'] ?? '') : $x));
				if ($x !== '') {
					$out[] = $x;
				}
			}
			return $out;
		};
		$first = fn (string $key): string => $list($key)[0] ?? '';
		$emails = array_values(array_unique(array_filter($list('EMAIL'), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false)));
		$n = explode(';', $first('N'));
		$name = $first('FN');
		if ($name === '' && $emails === []) {
			return null;
		}
		$adr = array_pad(explode(';', $first('ADR')), 7, '');
		$tels = $list('TEL');
		$org = explode(';', $first('ORG'));
		return [
			'id' => (string)($entry['UID'] ?? ''),
			'isGroup' => false,
			'members' => [],
			'office' => '',
			'hasPhoto' => false,
			'avatarUser' => (string)($entry['UID'] ?? ''),
			'displayName' => $name !== '' ? $name : $emails[0],
			'givenName' => trim($n[1] ?? ''),
			'surname' => trim($n[0] ?? ''),
			'company' => trim($org[0] ?? ''),
			'department' => trim($org[1] ?? ''),
			'jobTitle' => $first('TITLE'),
			'emails' => array_slice($emails, 0, 3),
			'mobilePhone' => $tels[0] ?? '',
			'businessPhone' => $tels[1] ?? '',
			'homePhone' => '',
			'street' => trim($adr[2]),
			'city' => trim($adr[3]),
			'postalCode' => trim($adr[5]),
			'country' => trim($adr[6]),
			'notes' => '',
			'matrix' => '',
			'birthday' => '',
		];
	}
}
