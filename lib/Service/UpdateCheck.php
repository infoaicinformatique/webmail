<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Nouvelle version disponible »: the versions published after the one installed
 * (from the releases of the project), with what each one brings. Pure: no network.
 */
class UpdateCheck {
	/** most versions told, the newest first */
	public const MAX = 10;
	public const NOTES_MAX = 4000;

	/**
	 * The published versions newer than $installed, the newest first (drafts and
	 * pre-releases left out).
	 * @param array<int, mixed> $releases the answer of the releases API
	 * @return list<array{version: string, url: string, date: string, notes: string}>
	 */
	public static function newer(string $installed, array $releases): array {
		$out = [];
		foreach ($releases as $r) {
			if (!is_array($r) || !empty($r['draft']) || !empty($r['prerelease'])) {
				continue;
			}
			$v = self::version((string)($r['tag_name'] ?? ''));
			if ($v === null || ($installed !== '' && version_compare($v, $installed, '<='))) {
				continue;
			}
			$url = (string)($r['html_url'] ?? '');
			$out[$v] = [
				'version' => $v,
				'url' => preg_match('#^https://#', $url) ? $url : '',
				'date' => substr((string)($r['published_at'] ?? ''), 0, 10),
				'notes' => mb_substr(trim(str_replace("\r\n", "\n", (string)($r['body'] ?? ''))), 0, self::NOTES_MAX),
			];
		}
		uksort($out, fn ($a, $b) => version_compare((string)$b, (string)$a));
		return array_slice(array_values($out), 0, self::MAX);
	}

	/** « v1.150.0 » → « 1.150.0 »; null for a tag that is not a version */
	public static function version(string $tag): ?string {
		$v = ltrim(trim($tag), 'vV');
		return preg_match('/^\d+(\.\d+){1,3}$/', $v) ? $v : null;
	}
}
