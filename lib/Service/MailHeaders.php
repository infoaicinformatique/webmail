<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * The Internet headers of a received message (as Exchange kept them), read for a person:
 * each header, the checks of the receiving server (SPF, DKIM, DMARC) and the servers it went through.
 */
class MailHeaders {
	/** Headers longer than this are cut (a few spam messages carry huge ones). */
	public const MAX_BYTES = 262144;

	/**
	 * @return array{raw: string, headers: list<array{name: string, value: string}>,
	 *               auth: array<string, string>, hops: list<array{from: string, by: string, date: ?string}>}
	 */
	public static function parse(string $raw): array {
		$raw = str_replace("\r\n", "\n", $raw);
		if (strlen($raw) > self::MAX_BYTES) {
			$raw = mb_strcut($raw, 0, self::MAX_BYTES, 'UTF-8');
		}
		$raw = trim($raw, "\n");
		$headers = [];
		// a header goes on over the next lines that start with a space or a tab
		foreach (preg_split('/\n(?![ \t])/', $raw) ?: [] as $line) {
			if (!preg_match('/^([!-9;-~]+):[ \t]*(.*)$/s', $line, $m)) {
				continue;
			}
			$value = trim((string)preg_replace('/\n[ \t]+/', ' ', $m[2]));
			$headers[] = ['name' => $m[1], 'value' => self::decode($value)];
		}
		return ['raw' => $raw, 'headers' => $headers, 'auth' => self::auth($headers), 'hops' => self::hops($headers)];
	}

	/** =?UTF-8?Q?…?= words of a header made readable. */
	private static function decode(string $value): string {
		if (!str_contains($value, '=?')) {
			return $value;
		}
		$decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
		return is_string($decoded) && $decoded !== '' ? $decoded : $value;
	}

	/**
	 * spf, dkim, dmarc: the result ("pass", "fail", "softfail", "none"…) given by the first
	 * Authentication-Results header, the one added by the receiving server (the next ones come
	 * from the way and anyone could have written them); else Received-SPF for spf.
	 *
	 * @param list<array{name: string, value: string}> $headers
	 * @return array<string, string>
	 */
	private static function auth(array $headers): array {
		$auth = [];
		foreach ($headers as $h) {
			if (strcasecmp($h['name'], 'Authentication-Results') === 0) {
				foreach (['spf', 'dkim', 'dmarc'] as $check) {
					if (preg_match('/(?:^|[\s;])' . $check . '=([a-z]+)/i', $h['value'], $m)) {
						$auth[$check] = strtolower($m[1]);
					}
				}
				break;
			}
		}
		if (!isset($auth['spf'])) {
			foreach ($headers as $h) {
				if (strcasecmp($h['name'], 'Received-SPF') === 0 && preg_match('/^([a-z]+)/i', $h['value'], $m)) {
					$auth['spf'] = strtolower($m[1]);
					break;
				}
			}
		}
		return $auth;
	}

	/**
	 * The servers the message went through, the first one (the sender's) first.
	 *
	 * @param list<array{name: string, value: string}> $headers
	 * @return list<array{from: string, by: string, date: ?string}>
	 */
	private static function hops(array $headers): array {
		$hops = [];
		foreach ($headers as $h) {
			if (strcasecmp($h['name'], 'Received') !== 0) {
				continue;
			}
			$v = $h['value'];
			$at = strrpos($v, ';');
			$time = $at !== false ? strtotime(trim(substr($v, $at + 1))) : false;
			$hops[] = [
				'from' => preg_match('/\bfrom\s+([^\s;()]+)/i', $v, $m) ? $m[1] : '',
				'by' => preg_match('/\bby\s+([^\s;()]+)/i', $v, $m) ? $m[1] : '',
				'date' => $time !== false ? gmdate('Y-m-d\TH:i:s\Z', $time) : null,
			];
		}
		return array_reverse($hops);
	}
}
