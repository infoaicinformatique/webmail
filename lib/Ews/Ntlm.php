<?php

declare(strict_types=1);

namespace OCA\Webmail\Ews;

use OCA\Webmail\Exception\MailException;

/**
 * NTLMv2 authentication (MS-NLMP), done here rather than by libcurl: the PHP of some
 * Nextcloud images (Nextcloud AIO, Alpine) has a libcurl built without NTLM, and OVH
 * Hosted Exchange refuses Basic. Messages only: no signing nor sealing is asked.
 */
final class Ntlm {
	private const SIGNATURE = "NTLMSSP\0";

	private const UNICODE = 0x00000001;
	private const OEM = 0x00000002;
	private const REQUEST_TARGET = 0x00000004;
	private const NTLM = 0x00000200;
	private const ALWAYS_SIGN = 0x00008000;
	private const EXTENDED_SESSION_SECURITY = 0x00080000;
	private const TARGET_INFO = 0x00800000;
	private const KEY_128 = 0x20000000;
	private const KEY_56 = 0x80000000;

	/** MsvAvTimestamp: the time of the server, in its challenge */
	private const AV_TIMESTAMP = 7;

	/** The first message (NEGOTIATE), base64. */
	public static function negotiate(): string {
		$flags = self::UNICODE | self::OEM | self::REQUEST_TARGET | self::NTLM | self::ALWAYS_SIGN | self::EXTENDED_SESSION_SECURITY;
		// domain and workstation: none (empty security buffers)
		return base64_encode(self::SIGNATURE . pack('V', 1) . pack('V', $flags) . pack('vvV', 0, 0, 0) . pack('vvV', 0, 0, 0));
	}

	/**
	 * The second message (CHALLENGE) of the server, base64.
	 *
	 * @return array{challenge: string, flags: int, targetInfo: string}
	 * @throws MailException
	 */
	public static function parseChallenge(string $base64): array {
		$m = base64_decode(trim($base64), true);
		if ($m === false || strlen($m) < 32 || substr($m, 0, 8) !== self::SIGNATURE || unpack('V', substr($m, 8, 4))[1] !== 2) {
			throw new MailException('Réponse NTLM invalide du serveur Exchange');
		}
		$targetInfo = '';
		if (strlen($m) >= 48) {
			$buf = unpack('vlen/vmax/Voffset', substr($m, 40, 8));
			if ($buf['len'] > 0 && $buf['offset'] + $buf['len'] <= strlen($m)) {
				$targetInfo = substr($m, $buf['offset'], $buf['len']);
			}
		}
		return ['challenge' => substr($m, 24, 8), 'flags' => unpack('V', substr($m, 20, 4))[1], 'targetInfo' => $targetInfo];
	}

	/**
	 * The third message (AUTHENTICATE), base64. The user is an address
	 * (user@domain, as OVH asks) or DOMAIN\user.
	 *
	 * @param array{challenge: string, flags: int, targetInfo: string} $challenge
	 * @param ?string $clientChallenge 8 bytes (random by default; given by the tests)
	 * @param ?string $time 8 bytes, tenths of microseconds since 1601 (the server's by default)
	 */
	public static function authenticate(string $user, string $password, array $challenge, ?string $clientChallenge = null, ?string $time = null): string {
		$domain = '';
		if (str_contains($user, '\\')) {
			[$domain, $user] = explode('\\', $user, 2);
		}
		$key = self::ntowfv2($user, $domain, $password);
		$clientChallenge ??= random_bytes(8);
		$serverTime = self::avPair($challenge['targetInfo'], self::AV_TIMESTAMP);
		$time ??= $serverTime ?? self::now();

		$blob = "\x01\x01\0\0\0\0\0\0" . $time . $clientChallenge . "\0\0\0\0" . $challenge['targetInfo'] . "\0\0\0\0";
		$nt = hash_hmac('md5', $challenge['challenge'] . $blob, $key, true) . $blob;
		// with the time of the server, the LM response is left empty (MS-NLMP 3.1.5.1.2)
		$lm = $serverTime !== null
			? str_repeat("\0", 24)
			: hash_hmac('md5', $challenge['challenge'] . $clientChallenge, $key, true) . $clientChallenge;

		$unicode = ($challenge['flags'] & self::UNICODE) !== 0;
		$text = fn (string $s): string => $unicode ? self::utf16($s) : $s;
		$fields = [$lm, $nt, $text($domain), $text($user), $text(''), ''];
		$flags = ($unicode ? self::UNICODE : self::OEM) | self::REQUEST_TARGET | self::NTLM | self::ALWAYS_SIGN
			| ($challenge['flags'] & (self::EXTENDED_SESSION_SECURITY | self::TARGET_INFO | self::KEY_128 | self::KEY_56));

		$head = self::SIGNATURE . pack('V', 3);
		$data = '';
		$offset = 64;
		foreach ($fields as $field) {
			$head .= pack('vvV', strlen($field), strlen($field), $offset + strlen($data));
			$data .= $field;
		}
		return base64_encode($head . pack('V', $flags) . $data);
	}

	/** NTOWFv2: the key made of the password, the user (in capitals) and the domain. */
	public static function ntowfv2(string $user, string $domain, string $password): string {
		return hash_hmac('md5', self::utf16(mb_strtoupper($user, 'UTF-8') . $domain), hash('md4', self::utf16($password), true), true);
	}

	/** A value of the AV pairs of the server (MS-NLMP 2.2.2.1), null when absent. */
	public static function avPair(string $targetInfo, int $id): ?string {
		$pos = 0;
		while ($pos + 4 <= strlen($targetInfo)) {
			$pair = unpack('vid/vlen', substr($targetInfo, $pos, 4));
			if ($pair['id'] === 0) {
				break; // MsvAvEOL
			}
			if ($pair['id'] === $id) {
				return substr($targetInfo, $pos + 4, $pair['len']);
			}
			$pos += 4 + $pair['len'];
		}
		return null;
	}

	private static function utf16(string $s): string {
		return mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
	}

	/** Now, in tenths of microseconds since 1601-01-01, 8 bytes little endian. */
	private static function now(): string {
		$t = (int)round((microtime(true) + 11644473600) * 10000000);
		return pack('P', $t);
	}
}
