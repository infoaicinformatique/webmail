<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Exception\MailException;

/**
 * « Classer les pièces jointes dans Fichiers »: the attachments of the messages of a sender
 * (an address, or « @domaine » for all its people) go into a folder of Files, as they arrive.
 * Rules kept in the user's preferences, per account. Pure functions.
 */
class AttachmentRules {
	public const MAX = 50;

	/**
	 * @return array{from: string, folder: string}
	 * @throws MailException
	 */
	public static function rule(string $from, string $folder): array {
		$from = mb_strtolower(trim($from));
		$isDomain = str_starts_with($from, '@') && preg_match('/^@[a-z0-9.-]+\.[a-z]{2,}$/i', $from);
		if (!$isDomain && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
			throw new MailException('Expéditeur invalide : %s (une adresse, ou @domaine.fr)', [$from]);
		}
		$folder = '/' . trim(str_replace('\\', '/', trim($folder)), '/');
		if ($folder === '/' || preg_match('#(^|/)\.\.?(/|$)#', $folder)) {
			throw new MailException('Choisissez un dossier de Fichiers');
		}
		return ['from' => $from, 'folder' => $folder];
	}

	/** @return list<array{from: string, folder: string}> */
	public static function decode(string $json): array {
		$out = [];
		foreach (json_decode($json, true) ?: [] as $r) {
			try {
				$out[] = self::rule((string)($r['from'] ?? ''), (string)($r['folder'] ?? ''));
			} catch (MailException) {
				// (an entry spoilt by hand: left out)
			}
		}
		return $out;
	}

	/**
	 * @param list<array{from: string, folder: string}> $rules
	 * @return list<array{from: string, folder: string}> one rule per sender: the new one replaces
	 * @throws MailException
	 */
	public static function add(array $rules, string $from, string $folder): array {
		$rule = self::rule($from, $folder);
		$rules = array_values(array_filter($rules, fn ($r) => $r['from'] !== $rule['from']));
		$rules[] = $rule;
		if (count($rules) > self::MAX) {
			throw new MailException('%s règles au plus', [(string)self::MAX]);
		}
		return $rules;
	}

	/**
	 * The folder for a sender: the rule of the address first, else that of its domain.
	 * @param list<array{from: string, folder: string}> $rules
	 */
	public static function folderFor(array $rules, string $email): ?string {
		$email = mb_strtolower(trim($email));
		$domain = str_contains($email, '@') ? substr($email, strrpos($email, '@')) : '';
		$byDomain = null;
		foreach ($rules as $r) {
			if ($r['from'] === $email) {
				return $r['folder'];
			}
			if ($domain !== '' && $r['from'] === $domain) {
				$byDomain = $r['folder'];
			}
		}
		return $byDomain;
	}
}
