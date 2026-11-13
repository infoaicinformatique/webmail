<?php

/**
 * The translations of the app: l10n/source/<language>.json (French text => translation)
 * become the files Nextcloud loads (l10n/<language>.json for PHP, l10n/<language>.js for
 * the page). French is the language of the app: no file for it.
 *
 *     php l10n/build.php
 */

declare(strict_types=1);

$dir = __DIR__;
$plural = 'nplurals=2; plural=(n != 1);';
foreach (glob($dir . '/source/*.json') as $source) {
	$lang = basename($source, '.json');
	$translations = json_decode((string)file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
	ksort($translations);
	$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
	// Nextcloud has « en » and « en_GB »: the same English for both
	foreach ($lang === 'en' ? ['en', 'en_GB'] : [$lang] as $code) {
		file_put_contents($dir . '/' . $code . '.json', json_encode(['translations' => $translations, 'pluralForm' => $plural], $flags) . "\n");
		file_put_contents($dir . '/' . $code . '.js', 'OC.L10N.register(' . "\n\t\"webmail\",\n\t" . json_encode($translations, $flags) . ",\n\t\"" . $plural . "\");\n");
	}
}
