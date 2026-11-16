<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Pièces jointes »: the kind of a file received (for the filter of the view), and
 * the rows of the view from the messages found and their files. Pure.
 */
class AttachmentKinds {
	public const KINDS = ['pdf', 'image', 'document', 'sheet', 'presentation', 'archive', 'other'];

	private const EXT = [
		'pdf' => ['pdf'],
		'image' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic', 'svg'],
		'document' => ['doc', 'docx', 'odt', 'rtf', 'txt', 'pages'],
		'sheet' => ['xls', 'xlsx', 'ods', 'csv', 'numbers'],
		'presentation' => ['ppt', 'pptx', 'odp', 'key'],
		'archive' => ['zip', 'rar', '7z', 'tar', 'gz', 'tgz'],
	];

	public static function kind(string $name, string $contentType = ''): string {
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		foreach (self::EXT as $kind => $list) {
			if (in_array($ext, $list, true)) {
				return $kind;
			}
		}
		$type = strtolower($contentType);
		if ($type === 'application/pdf') {
			return 'pdf';
		}
		if (str_starts_with($type, 'image/')) {
			return 'image';
		}
		return 'other';
	}

	/**
	 * One row per file: its message beside it (subject, sender, date, folder), the newest first
	 * as the messages are, only the kind asked ('' for all) and, with $words, only the files
	 * whose name or message has them.
	 * @param list<array<string, mixed>> $messages
	 * @param array<string, list<array<string, mixed>>> $files item id => its files
	 * @return list<array<string, mixed>>
	 */
	public static function rows(array $messages, array $files, string $kind, string $words = ''): array {
		$out = [];
		$words = mb_strtolower(trim($words));
		foreach ($messages as $m) {
			foreach ($files[(string)$m['id']] ?? [] as $f) {
				$k = self::kind((string)$f['name'], (string)($f['contentType'] ?? ''));
				if ($kind !== '' && $k !== $kind) {
					continue;
				}
				$out[] = $f + [
					'kind' => $k,
					'messageId' => (string)$m['id'],
					'subject' => (string)($m['subject'] ?? ''),
					'from' => $m['from'] ?? null,
					'date' => (string)($m['date'] ?? ''),
					'folderId' => (string)($m['folderId'] ?? ''),
				];
			}
		}
		return $out;
	}
}
