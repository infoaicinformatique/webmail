<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Exception\MailException;

/**
 * The rules of « Envoyer sur Matrix »: the homeserver, the room, the message.
 * Pure functions, so they are tested without Nextcloud nor a Matrix server.
 */
class Matrix {
	/** At most this many characters sent in one message. */
	public const MAX_TEXT = 4000;

	/**
	 * The address of a homeserver, « https://matrix.example.org » (no path); « matrix.example.org » alone is taken as https.
	 *
	 * @throws MailException
	 */
	public static function homeserver(string $value): string {
		$v = trim($value);
		if ($v !== '' && !preg_match('~^[a-z][a-z0-9+.-]*://~i', $v)) {
			$v = 'https://' . $v;
		}
		$p = parse_url($v);
		if ($p === false || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host'])
			|| isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || !in_array($p['path'] ?? '/', ['', '/'], true)) {
			throw new MailException('Adresse du serveur Matrix invalide (par exemple %s)', ['https://matrix.example.org']);
		}
		return 'https://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
	}

	/**
	 * A room: its id « !abc:example.org » or an alias « #equipe:example.org », also from a matrix.to link.
	 *
	 * @return array{type: string, value: string} type « id » or « alias »
	 * @throws MailException
	 */
	public static function room(string $value): array {
		$v = trim($value);
		if (preg_match('~^https://matrix\.to/#/([^/?]+)~i', $v, $m)) {
			$v = rawurldecode($m[1]);
		}
		if (strlen($v) <= 255 && preg_match('~^([!#])[^\s:/]+:[a-z0-9.\-\[\]:]+$~i', $v, $m)) {
			return ['type' => $m[1] === '!' ? 'id' : 'alias', 'value' => $v];
		}
		throw new MailException('Salon Matrix invalide : %s (par exemple %s)', [$value, '#equipe:example.org']);
	}

	/**
	 * The m.room.message sent: the text as typed, and as HTML (escaped, line breaks, links clickable).
	 *
	 * @return array{msgtype: string, body: string, format: string, formatted_body: string}
	 * @throws MailException
	 */
	public static function message(string $text): array {
		$text = trim(str_replace("\r\n", "\n", $text));
		if ($text === '') {
			throw new MailException('Message vide');
		}
		if (mb_strlen($text) > self::MAX_TEXT) {
			$text = rtrim(mb_substr($text, 0, self::MAX_TEXT - 1)) . '…';
		}
		$html = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$html = (string)preg_replace('~https?://[^\s<>"\']+~', '<a href="$0">$0</a>', $html);
		return ['msgtype' => 'm.text', 'body' => $text, 'format' => 'org.matrix.custom.html', 'formatted_body' => nl2br($html, false)];
	}

	/** A transaction id: the same message sent twice (a retry) is not posted twice. */
	public static function txnId(string $userId, string $roomId, string $text, int $minute): string {
		return 'webmail-' . substr(hash('sha256', $userId . '|' . $roomId . '|' . $text . '|' . $minute), 0, 24);
	}
}
