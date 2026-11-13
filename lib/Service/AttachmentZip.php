<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Exception\MailException;

/**
 * The attachments of a message in one .zip file, to download in one click.
 */
class AttachmentZip {
	/** At most this many files (Exchange messages rarely have more). */
	public const MAX_FILES = 100;

	/**
	 * A file name: no folder, no control or reserved character (Windows), not too long.
	 */
	public static function safeName(string $name, string $fallback): string {
		$name = (string)preg_replace('~[\\\\/:*?"<>|\x00-\x1f\x7f]+~u', ' ', $name);
		$name = trim((string)preg_replace('/\s+/u', ' ', $name), ' .');
		if (mb_strlen($name) > 120) {
			// keep the extension
			$ext = pathinfo($name, PATHINFO_EXTENSION);
			$keep = $ext !== '' && mb_strlen($ext) <= 10 ? '.' . $ext : '';
			$name = rtrim(mb_substr($name, 0, 120 - mb_strlen($keep)), ' .') . $keep;
		}
		return $name !== '' ? $name : $fallback;
	}

	/**
	 * @param list<array{name: string, content: string}> $files
	 * @return string the bytes of the .zip
	 * @throws MailException
	 */
	public static function build(array $files): string {
		if (!$files) {
			throw new MailException('Aucune pièce jointe');
		}
		if (count($files) > self::MAX_FILES) {
			throw new MailException('Trop de pièces jointes');
		}
		$tmp = tempnam(sys_get_temp_dir(), 'webmail-zip-');
		if ($tmp === false) {
			throw new MailException('Impossible de préparer le fichier .zip');
		}
		try {
			$zip = new \ZipArchive();
			if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
				throw new MailException('Impossible de préparer le fichier .zip');
			}
			$used = [];
			foreach ($files as $i => $file) {
				$name = self::safeName($file['name'], 'piece-jointe-' . ($i + 1));
				// two attachments with the same name: « devis (2).pdf »
				$base = $name;
				for ($n = 2; isset($used[mb_strtolower($name)]); $n++) {
					$ext = pathinfo($base, PATHINFO_EXTENSION);
					$name = $ext !== '' ? mb_substr($base, 0, -mb_strlen($ext) - 1) . ' (' . $n . ').' . $ext : $base . ' (' . $n . ')';
				}
				$used[mb_strtolower($name)] = true;
				$zip->addFromString($name, $file['content']);
				// a plain file once unzipped (libzip marks non-ASCII names as UTF-8 by itself)
				$zip->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, 0644 << 16);
			}
			if (!$zip->close()) {
				throw new MailException('Impossible de préparer le fichier .zip');
			}
			return (string)file_get_contents($tmp);
		} finally {
			@unlink($tmp);
		}
	}
}
