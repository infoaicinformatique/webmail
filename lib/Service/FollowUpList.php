<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Exception\MailException;

/**
 * « Me rappeler si pas de réponse »: the messages sent waiting for an answer, as kept in the
 * user's preferences: {subject, to (addresses), since (sent, UTC), until (UTC)}.
 * Pure functions, so the rules are tested without Nextcloud.
 */
class FollowUpList {
	/** At most this many messages waited for, per account. */
	public const MAX = 200;
	public const MAX_DAYS = 60;

	/** The subject without « RE : », « TR : », « Fwd: »…: the answer's subject contains it. */
	public static function subject(string $subject): string {
		$s = trim((string)preg_replace('/^\s*((re|tr|fw|fwd|aw|wg)\s*(\[\d+\])?\s*:\s*)+/iu', '', $subject));
		return mb_substr($s, 0, 200);
	}

	/**
	 * The addresses of « To » (« Jean <jean@x.fr>, paul@y.fr »), lower case, each once.
	 * @return list<string>
	 */
	public static function addresses(string $to): array {
		preg_match_all('/[^\s<>,;"]+@[^\s<>,;"]+\.[^\s<>,;"]+/u', $to, $m);
		return array_values(array_unique(array_map('mb_strtolower', array_filter($m[0], fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false))));
	}

	/**
	 * @param list<array<string, mixed>> $list
	 * @return list<array{subject: string, to: list<string>, since: string, until: string}>
	 * @throws MailException
	 */
	public static function add(array $list, string $subject, string $to, int $days, int $now): array {
		$addresses = self::addresses($to);
		if ($addresses === []) {
			throw new MailException('Aucun destinataire à attendre');
		}
		if ($days < 1 || $days > self::MAX_DAYS) {
			throw new MailException('Entre 1 et %s jours', [(string)self::MAX_DAYS]);
		}
		$list[] = [
			'subject' => self::subject($subject),
			'to' => array_slice($addresses, 0, 20),
			'since' => gmdate('Y-m-d\TH:i:s\Z', $now - 60),
			'until' => gmdate('Y-m-d\TH:i:s\Z', $now + $days * 86400),
		];
		if (count($list) > self::MAX) {
			throw new MailException('Trop de messages en attente de réponse (%s au plus)', [(string)self::MAX]);
		}
		return $list;
	}

	/** @return list<array{subject: string, to: list<string>, since: string, until: string}> */
	public static function decode(string $json): array {
		$list = json_decode($json, true);
		$out = [];
		foreach (is_array($list) ? $list : [] as $e) {
			if (is_array($e) && is_string($e['subject'] ?? null) && is_array($e['to'] ?? null) && $e['to'] !== []
				&& is_string($e['since'] ?? null) && is_string($e['until'] ?? null)) {
				$out[] = ['subject' => $e['subject'], 'to' => array_values(array_map('strval', $e['to'])), 'since' => $e['since'], 'until' => $e['until']];
			}
		}
		return $out;
	}

	/**
	 * The entries whose time has come, and the others.
	 * @param list<array{subject: string, to: list<string>, since: string, until: string}> $list
	 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
	 */
	public static function due(array $list, int $now): array {
		$due = [];
		$rest = [];
		foreach ($list as $e) {
			$t = strtotime($e['until']);
			if ($t === false || $t <= $now) {
				$due[] = $e;
			} else {
				$rest[] = $e;
			}
		}
		return [$due, $rest];
	}

	/**
	 * Is one of these messages an answer: from one of the people written to, with the subject in its own.
	 * @param list<array{from: ?array{email: string}, subject: string}> $received
	 * @param list<string> $to
	 */
	public static function answered(array $received, string $subject, array $to): bool {
		foreach ($received as $m) {
			$from = mb_strtolower((string)($m['from']['email'] ?? ''));
			if ($from !== '' && in_array($from, $to, true)
				&& ($subject === '' || mb_stripos(self::subject((string)($m['subject'] ?? '')), $subject) !== false)) {
				return true;
			}
		}
		return false;
	}
}
