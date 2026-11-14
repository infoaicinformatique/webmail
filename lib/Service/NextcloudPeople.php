<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCP\Contacts\IManager;
use Psr\Log\LoggerInterface;

/**
 * The people of Nextcloud for the completion of addresses: the user's Nextcloud Contacts and
 * the accounts of this Nextcloud (system address book, within what the administrator lets
 * users see of each other).
 */
class NextcloudPeople {
	public const MAX = 10;

	public function __construct(
		private IManager $contacts,
		private LoggerInterface $logger,
	) {
	}

	/** @return list<array{name: string, email: string, source: string}> */
	public function search(string $query): array {
		$query = trim($query);
		if (mb_strlen($query) < 2 || !$this->contacts->isEnabled()) {
			return [];
		}
		try {
			$found = $this->contacts->search($query, ['FN', 'EMAIL'], ['limit' => self::MAX * 2, 'enumeration' => true, 'fullmatch' => false]);
		} catch (\Throwable $e) {
			$this->logger->info('Webmail: Nextcloud contacts not searched: ' . $e->getMessage(), ['app' => 'webmail']);
			return [];
		}
		return self::people(is_array($found) ? $found : []);
	}

	/**
	 * The results of the Contacts API as people: one line per address, those of the
	 * Nextcloud accounts marked as such.
	 *
	 * @param array<int, array<string, mixed>> $found
	 * @return list<array{name: string, email: string, source: string}>
	 */
	public static function people(array $found): array {
		$out = [];
		$seen = [];
		foreach ($found as $c) {
			$emails = $c['EMAIL'] ?? [];
			$emails = is_array($emails) ? $emails : [$emails];
			$name = trim((string)($c['FN'] ?? ''));
			$source = !empty($c['isLocalSystemBook']) ? 'nextcloud-user' : 'nextcloud';
			foreach ($emails as $e) {
				// (an entry can be ['type' => …, 'value' => …])
				$e = mb_strtolower(trim((string)(is_array($e) ? ($e['value'] ?? '') : $e)));
				if ($e === '' || filter_var($e, FILTER_VALIDATE_EMAIL) === false || isset($seen[$e])) {
					continue;
				}
				$seen[$e] = true;
				$out[] = ['name' => $name !== '' ? $name : $e, 'email' => $e, 'source' => $source];
				if (count($out) >= self::MAX) {
					return $out;
				}
			}
		}
		return $out;
	}

	/**
	 * Exchange's results first (contacts, directory), then those of Nextcloud not already there.
	 *
	 * @param list<array<string, mixed>> $exchange
	 * @param list<array{name: string, email: string, source: string}> $nextcloud
	 * @return list<array<string, mixed>>
	 */
	public static function merge(array $exchange, array $nextcloud): array {
		$seen = [];
		foreach ($exchange as $r) {
			$seen[mb_strtolower((string)($r['email'] ?? ''))] = true;
		}
		foreach ($nextcloud as $r) {
			if (!isset($seen[$r['email']])) {
				$exchange[] = $r;
				$seen[$r['email']] = true;
			}
		}
		return $exchange;
	}
}
