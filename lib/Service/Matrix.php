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

	/**
	 * The room of a meeting (createRoom): a video room of Element (its call opens at
	 * once), that any account of the server joins by its link, not listed in the
	 * directory, nor open to other servers. Everyone may take part in the call.
	 *
	 * @return array<string, mixed>
	 */
	public static function meetingRoom(string $subject, string $when = ''): array {
		$name = trim(preg_replace('/\s+/u', ' ', $subject) ?? '');
		$name = $name !== '' ? mb_substr($name, 0, 120) : 'Réunion';
		$room = [
			'name' => $name,
			'preset' => 'public_chat',
			'visibility' => 'private',
			'creation_content' => ['m.federate' => false, 'type' => 'org.matrix.msc3417.call'],
			'power_level_content_override' => [
				// MatrixRTC: each participant says it is in the call
				'events' => ['org.matrix.msc3401.call.member' => 0, 'org.matrix.msc3401.call' => 100],
			],
			'initial_state' => [['type' => 'm.room.history_visibility', 'state_key' => '', 'content' => ['history_visibility' => 'joined']]],
		];
		if (trim($when) !== '') {
			$room['topic'] = mb_substr(trim($when), 0, 200);
		}
		return $room;
	}

	/**
	 * The link of a room in Element Web: « https://chat.example.org/#/room/!id:server?via=server ».
	 *
	 * @throws MailException
	 */
	public static function roomLink(string $elementUrl, string $roomId): string {
		if (!preg_match('~^![^\s:/]+:([a-z0-9.\-\[\]:]+)$~i', $roomId, $m)) {
			throw new MailException('Le serveur Matrix n\'a pas créé le salon');
		}
		return rtrim($elementUrl, '/') . '/#/room/' . rawurlencode($roomId) . '?via=' . rawurlencode($m[1]);
	}

	/**
	 * The room of a meeting in a text (its place, its description): from its link in
	 * Element Web (« …/#/room/%21abc%3Aserver?via=… ») or its id written as is.
	 */
	public static function roomIdIn(string $text): ?string {
		if (preg_match('~/#/room/(%21[^\s?"\'<>#]+)~i', $text, $m)) {
			$id = rawurldecode($m[1]);
		} elseif (preg_match('~(?<![\w#])(![A-Za-z0-9._=\-]+:[a-z0-9.\-]+)~i', $text, $m)) {
			$id = $m[1];
		} else {
			return null;
		}
		return preg_match('~^![^\s:/]+:[a-z0-9.\-\[\]:]+$~i', $id) ? $id : null;
	}

	/**
	 * The Matrix ids a Nextcloud account may have on the chat of the organisation, the
	 * likeliest first: its Nextcloud user id (« preferred_username » of the sign-in), then
	 * the part of its address before « @ » (accounts named « First Last (f.last@…) »).
	 * Each one is checked on the server before an invitation.
	 *
	 * @return list<string> « @f.last:server »
	 */
	public static function candidates(string $uid, string $email, string $serverName): array {
		$out = [];
		foreach ([$uid, explode('@', $email)[0]] as $local) {
			$local = strtolower(trim($local));
			// the characters of a Matrix user id (lower case, digits, . _ = - /)
			if ($local !== '' && preg_match('~^[a-z0-9._=\-/]+$~', $local)) {
				$id = '@' . $local . ':' . $serverName;
				if (!in_array($id, $out, true)) {
					$out[] = $id;
				}
			}
		}
		return $out;
	}

	/**
	 * The Element Web sites among the « Sites externes » of Nextcloud (app external):
	 * their https addresses, the first ones first.
	 *
	 * @return list<array{name: string, url: string}>
	 */
	public static function externalSites(string $sitesJson): array {
		$sites = json_decode($sitesJson, true);
		$out = [];
		foreach (is_array($sites) ? $sites : [] as $site) {
			$url = is_array($site) ? trim((string)($site['url'] ?? '')) : '';
			// (Element Web opened on a page: « https://chat.example.org/#/home »)
			$url = (string)preg_replace('~[?#].*$~s', '', $url);
			if (preg_match('~^https://[^\s/?#]+(/[^\s?#]*)?$~i', $url)) {
				$out[] = ['name' => trim((string)($site['name'] ?? '')), 'url' => rtrim($url, '/')];
			}
		}
		return $out;
	}

	/**
	 * The homeserver of an Element Web (its config.json), null when it is not one.
	 *
	 * @param array<string, mixed> $config
	 * @return ?array{homeserver: string, serverName: string}
	 */
	public static function elementHomeserver(array $config): ?array {
		$hs = $config['default_server_config']['m.homeserver'] ?? null;
		if (!is_array($hs) || !is_string($hs['base_url'] ?? null)) {
			return null;
		}
		try {
			return ['homeserver' => self::homeserver($hs['base_url']), 'serverName' => (string)($hs['server_name'] ?? '')];
		} catch (MailException) {
			return null;
		}
	}

	/** A transaction id: the same message sent twice (a retry) is not posted twice. */
	public static function txnId(string $userId, string $roomId, string $text, int $minute): string {
		return 'webmail-' . substr(hash('sha256', $userId . '|' . $roomId . '|' . $text . '|' . $minute), 0, 24);
	}
}
