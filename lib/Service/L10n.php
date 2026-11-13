<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCP\IL10N;

/**
 * Translation for the static helpers that also run without Nextcloud (unit
 * tests): without an IL10N, the French text, as it is the key.
 */
final class L10n {
	/**
	 * @param string $text the French text, with %s placeholders when $params is given
	 * @param array<int, string|int|float> $params
	 */
	public static function t(?IL10N $l, string $text, array $params = []): string {
		if ($l !== null) {
			return $l->t($text, $params);
		}
		return $params === [] ? $text : vsprintf($text, $params);
	}
}
