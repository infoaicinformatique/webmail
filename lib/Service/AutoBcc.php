<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Copie cachée automatique »: addresses put in Bcc of every message sent from an account
 * (a CRM, an archive, a colleague), always, only when writing outside one's domain, or only
 * to some domains. Pure.
 */
class AutoBcc {
	public const PREF = 'autobcc_';
	public const MAX = 10;
	public const MAX_DOMAINS = 20;
	public const WHEN = ['all', 'external', 'domains'];

	/**
	 * Rules as kept (or as sent by the browser): the invalid ones left out.
	 * @return list<array{email: string, when: string, domains: list<string>}>
	 */
	public static function decode(string|array $json): array {
		$v = is_array($json) ? $json : json_decode($json, true);
		if (!is_array($v)) {
			return [];
		}
		$out = [];
		$seen = [];
		foreach ($v as $r) {
			if (!is_array($r)) {
				continue;
			}
			$email = mb_strtolower(trim((string)($r['email'] ?? '')));
			$when = (string)($r['when'] ?? 'all');
			if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($when, self::WHEN, true) || isset($seen[$email])) {
				continue;
			}
			$domains = $when === 'domains' ? self::domains($r['domains'] ?? []) : [];
			if ($when === 'domains' && $domains === []) {
				continue;
			}
			$seen[$email] = true;
			$out[] = ['email' => $email, 'when' => $when, 'domains' => $domains];
		}
		return array_slice($out, 0, self::MAX);
	}

	/**
	 * Strict check of what the user typed.
	 * @return list<array{email: string, when: string, domains: list<string>}>
	 * @throws \InvalidArgumentException 'address' (with the address), 'domains', 'too many'
	 */
	public static function validate(array $rules): array {
		if (count($rules) > self::MAX) {
			throw new \InvalidArgumentException('too many');
		}
		foreach ($rules as $r) {
			$email = trim((string)(is_array($r) ? ($r['email'] ?? '') : ''));
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				throw new \InvalidArgumentException('address:' . $email);
			}
			if (($r['when'] ?? 'all') === 'domains' && self::domains($r['domains'] ?? []) === []) {
				throw new \InvalidArgumentException('domains');
			}
		}
		return self::decode($rules);
	}

	/** @return list<string> */
	public static function domains(mixed $v): array {
		$list = is_array($v) ? $v : preg_split('/[\s,;]+/', (string)$v);
		$out = [];
		foreach ($list ?: [] as $d) {
			$d = ltrim(mb_strtolower(trim((string)$d)), '@');
			if ($d !== '' && preg_match('/^([a-z0-9](-*[a-z0-9])*\.)+[a-z]{2,}$/', $d) && !in_array($d, $out, true)) {
				$out[] = $d;
			}
		}
		return array_slice($out, 0, self::MAX_DOMAINS);
	}

	private static function domainOf(string $email): string {
		$at = strrpos($email, '@');
		return $at === false ? '' : mb_strtolower(substr($email, $at + 1));
	}

	/** a recipient in this domain or one of its subdomains */
	private static function inDomain(string $email, string $domain): bool {
		$d = self::domainOf($email);
		return $d === $domain || str_ends_with($d, '.' . $domain);
	}

	/**
	 * The addresses to add in Bcc to a message (those already among its recipients not twice).
	 * @param list<array{email: string, when: string, domains: list<string>}> $rules
	 * @param string[] $to @param string[] $cc @param string[] $bcc
	 * @return list<string>
	 */
	public static function extra(array $rules, string $me, array $to, array $cc, array $bcc): array {
		$recipients = array_values(array_unique(array_map(fn ($a) => mb_strtolower(trim((string)$a)), array_merge($to, $cc, $bcc))));
		$visible = array_values(array_unique(array_map(fn ($a) => mb_strtolower(trim((string)$a)), array_merge($to, $cc))));
		$mine = self::domainOf(mb_strtolower(trim($me)));
		$out = [];
		foreach ($rules as $r) {
			if (in_array($r['email'], $recipients, true) || in_array($r['email'], $out, true)) {
				continue;
			}
			$applies = match ($r['when']) {
				'all' => true,
				// someone outside the domain of the account among those written to
				'external' => $mine !== '' && array_filter($visible, fn ($a) => !self::inDomain($a, $mine)) !== [],
				'domains' => array_filter($visible, fn ($a) => array_filter($r['domains'], fn ($d) => self::inDomain($a, $d)) !== []) !== [],
				default => false,
			};
			if ($applies) {
				$out[] = $r['email'];
			}
		}
		return $out;
	}
}
