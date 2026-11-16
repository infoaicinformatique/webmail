<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Exception\MailException;

/**
 * « Exporter le dossier »: its messages, newest first, as .eml files in one .zip (for Outlook,
 * Thunderbird, or to keep). Written to a temporary file, message after message: a large folder
 * does not have to fit in memory.
 */
class FolderExport {
	/** at most this many messages… */
	public const MAX_MESSAGES = 2000;
	/** …and this many bytes of messages in one .zip */
	public const MAX_BYTES = 500 * 1024 * 1024;
	/** messages read from Exchange in one request */
	private const BATCH = 10;

	/** « 2026-11-16 1432 Devis signé.eml » (the date of the message in the zone given) */
	public static function fileName(string $subject, ?string $date, \DateTimeZone $zone): string {
		$prefix = '';
		if ($date !== null && $date !== '') {
			try {
				$prefix = (new \DateTimeImmutable($date))->setTimezone($zone)->format('Y-m-d Hi') . ' ';
			} catch (\Exception) {
			}
		}
		return AttachmentZip::safeName($prefix . ($subject !== '' ? $subject : 'Message'), 'message') . '.eml';
	}

	/** « Clients B (2026-11-16).zip » */
	public static function zipName(string $folder, \DateTimeImmutable $day): string {
		return AttachmentZip::safeName($folder, 'Dossier') . ' (' . $day->format('Y-m-d') . ').zip';
	}

	/** A name not used yet in the .zip: « Devis (2).eml » */
	public static function unique(string $name, array &$used): string {
		$base = mb_substr($name, 0, -4);
		for ($n = 2; isset($used[mb_strtolower($name)]); $n++) {
			$name = $base . ' (' . $n . ').eml';
		}
		$used[mb_strtolower($name)] = true;
		return $name;
	}

	/**
	 * @return array{path: string, count: int, total: int, truncated: bool} the .zip (a temporary file, for the caller to remove)
	 * @throws MailException
	 */
	public static function build(EwsClient $client, string $folderId, \DateTimeZone $zone): array {
		$ids = $client->folderItemIds($folderId, self::MAX_MESSAGES + 1);
		if ($ids === []) {
			throw new MailException('Ce dossier est vide');
		}
		$truncated = count($ids) > self::MAX_MESSAGES;
		$ids = array_slice($ids, 0, self::MAX_MESSAGES);
		$path = tempnam(sys_get_temp_dir(), 'webmail-export-');
		$dir = $path !== false ? $path . '.d' : false;
		if ($path === false || !@mkdir($dir)) {
			throw new MailException('Impossible de préparer le fichier .zip');
		}
		$zip = new \ZipArchive();
		if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			throw new MailException('Impossible de préparer le fichier .zip');
		}
		$used = [];
		$count = 0;
		$bytes = 0;
		$files = [];
		try {
			foreach (array_chunk($ids, self::BATCH) as $chunk) {
				foreach ($client->mimes($chunk) as $m) {
					if ($bytes + strlen($m['content']) > self::MAX_BYTES) {
						$truncated = true;
						break 2;
					}
					// each message to a file: the .zip reads them only when closed
					$file = $dir . '/' . count($files);
					file_put_contents($file, $m['content']);
					$files[] = $file;
					$zip->addFile($file, self::unique(self::fileName($m['subject'], $m['date'], $zone), $used));
					$bytes += strlen($m['content']);
					$count++;
				}
			}
			if ($count === 0) {
				throw new MailException('Ce dossier est vide');
			}
			if (!$zip->close()) {
				throw new MailException('Impossible de préparer le fichier .zip');
			}
		} catch (\Throwable $e) {
			@$zip->close();
			@unlink($path);
			throw $e;
		} finally {
			foreach ($files as $f) {
				@unlink($f);
			}
			@rmdir($dir);
		}
		return ['path' => $path, 'count' => $count, 'total' => count($ids), 'truncated' => $truncated];
	}
}
