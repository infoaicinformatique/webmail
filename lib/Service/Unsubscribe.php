<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Se désabonner » of a mailing list, from the headers it puts in each message:
 * List-Unsubscribe (RFC 2369) and List-Unsubscribe-Post (RFC 8058, one click).
 * Pure functions, so the rules are tested without Nextcloud.
 */
class Unsubscribe {
	/**
	 * How to unsubscribe, the best way first:
	 * - « oneclick »: an HTTPS address to POST « List-Unsubscribe=One-Click » to (done by the server);
	 * - « mailto »: a message to send (done by the server, from the mailbox);
	 * - « link »: a page to open (the list asks for a confirmation there).
	 *
	 * @return ?array{method: string, url?: string, to?: string, subject?: string, body?: string}
	 */
	public static function parse(string $header, string $post = ''): ?array {
		$https = null;
		$http = null;
		$mailto = null;
		// <uri>, <uri>: only what is between angle brackets counts (RFC 2369)
		preg_match_all('/<\s*([^>]+?)\s*>/', $header, $m);
		foreach ($m[1] as $uri) {
			$uri = (string)preg_replace('/\s+/', '', $uri);
			if ($https === null && self::isWebUrl($uri, true)) {
				$https = $uri;
			} elseif ($http === null && self::isWebUrl($uri, false)) {
				$http = $uri;
			} elseif ($mailto === null && ($mail = self::mailto($uri)) !== null) {
				$mailto = $mail;
			}
		}
		if ($https !== null && preg_match('/(^|[;&\s])List-Unsubscribe\s*=\s*One-Click(\s*$|[;&\s])/i', trim($post))) {
			return ['method' => 'oneclick', 'url' => $https];
		}
		if ($mailto !== null) {
			return ['method' => 'mailto'] + $mailto;
		}
		$link = $https ?? $http;
		return $link !== null ? ['method' => 'link', 'url' => $link] : null;
	}

	/** An http(s) address with a host, no user or password in it. */
	private static function isWebUrl(string $uri, bool $httpsOnly): bool {
		$p = parse_url($uri);
		if ($p === false || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
			return false;
		}
		$scheme = strtolower($p['scheme'] ?? '');
		return $scheme === 'https' || (!$httpsOnly && $scheme === 'http');
	}

	/** @return ?array{to: string, subject: string, body: string} */
	private static function mailto(string $uri): ?array {
		if (stripos($uri, 'mailto:') !== 0) {
			return null;
		}
		[$addr, $query] = array_pad(explode('?', substr($uri, 7), 2), 2, '');
		$addr = rawurldecode($addr);
		// one address only (« a@x,b@y »: not for us to write to several)
		if (filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
			return null;
		}
		$subject = '';
		$body = '';
		foreach (explode('&', $query) as $pair) {
			[$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
			$k = strtolower(rawurldecode($k));
			$v = rawurldecode($v);
			if ($k === 'subject') {
				$subject = $v;
			} elseif ($k === 'body') {
				$body = $v;
			}
		}
		$clean = fn (string $s, int $max) => mb_substr(trim((string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $s)), 0, $max);
		$subject = (string)preg_replace('/[\r\n]+/', ' ', $clean($subject, 200));
		return ['to' => $addr, 'subject' => $subject !== '' ? $subject : 'unsubscribe', 'body' => $clean($body, 2000)];
	}
}
