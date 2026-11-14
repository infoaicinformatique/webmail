<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * The copy of an Exchange address book in Nextcloud Contacts: one card per
 * Exchange contact, named after its Exchange id so that a new copy updates
 * the same cards. Pure functions, so the rules are tested without Nextcloud.
 */
class ContactCopy {
	/** The card of an Exchange contact (its file name in the address book). */
	public static function uri(string $itemId): string {
		return 'webmail-' . substr(sha1($itemId), 0, 32) . '.vcf';
	}

	/** The vCard of a contact, with the UID Nextcloud needs. */
	public static function card(array $contact, string $uri): string {
		$vcf = VCard::export([$contact]);
		$uid = substr($uri, 0, -4);
		return (string)preg_replace('/^VERSION:3\.0\r\n/m', "VERSION:3.0\r\nUID:" . $uid . "\r\n", $vcf, 1);
	}

	/**
	 * What to write: the cards to create, to update (changed since the last
	 * copy), and the ones whose contact is gone from Exchange.
	 *
	 * @param list<array<string, mixed>> $contacts the Exchange contacts (with their « id »)
	 * @param array<string, string> $existing card file name → vCard, in Nextcloud now
	 * @return array{create: array<string, string>, update: array<string, string>, delete: list<string>}
	 */
	public static function plan(array $contacts, array $existing): array {
		$plan = ['create' => [], 'update' => [], 'delete' => []];
		$seen = [];
		foreach ($contacts as $c) {
			$id = (string)($c['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$uri = self::uri($id);
			if (isset($seen[$uri])) {
				continue;
			}
			$seen[$uri] = true;
			$card = self::card($c, $uri);
			if (!isset($existing[$uri])) {
				$plan['create'][$uri] = $card;
			} elseif (self::normalize($existing[$uri]) !== self::normalize($card)) {
				$plan['update'][$uri] = $card;
			}
		}
		foreach (array_keys($existing) as $uri) {
			// only the cards we wrote: a card added by hand in Nextcloud stays
			if (!isset($seen[$uri]) && str_starts_with((string)$uri, 'webmail-')) {
				$plan['delete'][] = (string)$uri;
			}
		}
		return $plan;
	}

	private static function normalize(string $vcf): string {
		return trim(str_replace("\r\n", "\n", $vcf));
	}
}
