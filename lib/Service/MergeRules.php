<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * « Envoi personnalisé » (publipostage): the list of recipients read from a paste or a
 * CSV file, and each message filled with its row ({prenom}, {nom}, {societe}…). Pure.
 */
class MergeRules {
	/** most recipients of one sending */
	public const MAX = 500;

	/** the usual names of the columns, as written in a header (lower case, without accents) */
	private const ALIASES = [
		'email' => ['email', 'e-mail', 'mail', 'courriel', 'adresse', 'adresse e-mail', 'adresse email'],
		'prenom' => ['prenom', 'first name', 'firstname', 'given name'],
		'nom' => ['nom', 'last name', 'lastname', 'surname', 'name', 'nom de famille'],
		'societe' => ['societe', 'entreprise', 'organisation', 'company', 'organization'],
	];

	public static function fold(string $s): string {
		$t = \Normalizer::normalize(mb_strtolower(trim($s)), \Normalizer::FORM_D);
		return preg_replace('/\p{Mn}+/u', '', (string)$t) ?? '';
	}

	/** the column name a header cell stands for: email, prenom, nom, societe, or its own (folded) */
	public static function column(string $header): string {
		$h = self::fold($header);
		foreach (self::ALIASES as $key => $names) {
			if (in_array($h, $names, true)) {
				return $key;
			}
		}
		return preg_replace('/[^a-z0-9_]+/', '_', $h) ?: 'colonne';
	}

	public static function isEmail(string $s): bool {
		return filter_var($s, FILTER_VALIDATE_EMAIL) !== false;
	}

	/**
	 * The recipients of a paste or a CSV: one per line. A first line without an address is
	 * the header (its columns become fields); without header, « email ; prénom ; nom ; société ».
	 * Each address once; lines without a valid address are given back as refused.
	 * @return array{rows: list<array<string, string>>, refused: list<string>, columns: list<string>}
	 */
	public static function parse(string $text): array {
		$text = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $text)) ?? '';
		$lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));
		if ($lines === []) {
			return ['rows' => [], 'refused' => [], 'columns' => ['email', 'prenom', 'nom', 'societe']];
		}
		$sep = self::separator($lines[0]);
		$split = fn (string $l) => array_map(fn ($c) => trim(trim($c), '"'), str_getcsv($l, $sep, '"', ''));
		$first = $split($lines[0]);
		$hasHeader = !array_filter($first, fn ($c) => self::isEmail($c));
		$columns = $hasHeader ? array_map([self::class, 'column'], $first) : ['email', 'prenom', 'nom', 'societe'];
		if ($hasHeader) {
			array_shift($lines);
		}
		$emailAt = array_search('email', $columns, true);
		$rows = [];
		$refused = [];
		$seen = [];
		foreach ($lines as $line) {
			$cells = $split($line);
			$email = $emailAt !== false ? ($cells[$emailAt] ?? '') : '';
			if (!self::isEmail($email)) {
				// (an address anywhere in the line, when the columns do not say where)
				$found = array_values(array_filter($cells, fn ($c) => self::isEmail($c)));
				$email = $found[0] ?? '';
			}
			if (!self::isEmail($email)) {
				$refused[] = mb_substr($line, 0, 200);
				continue;
			}
			$key = mb_strtolower($email);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$row = [];
			foreach ($columns as $i => $col) {
				$row[$col] = mb_substr((string)($cells[$i] ?? ''), 0, 200);
			}
			$row['email'] = $email;
			$rows[] = $row + ['prenom' => '', 'nom' => '', 'societe' => ''];
		}
		return ['rows' => $rows, 'refused' => $refused, 'columns' => array_values(array_unique(array_merge($columns, ['email', 'prenom', 'nom', 'societe'])))];
	}

	private static function separator(string $line): string {
		$best = ';';
		$max = -1;
		foreach ([';', ',', "\t"] as $sep) {
			$n = substr_count($line, $sep);
			if ($n > $max) {
				$max = $n;
				$best = $sep;
			}
		}
		return $best;
	}

	/**
	 * A text with the fields of a row: {prenom}, {nom}… ({Prénom} too); $html: the values escaped.
	 * A field unknown or empty becomes nothing, with the space before it (« Bonjour {prenom}, » → « Bonjour, »).
	 */
	public static function fill(string $template, array $row, bool $html): string {
		$out = preg_replace_callback('/(\s?)\{([^{}\n]{1,40})\}/u', function (array $m) use ($row, $html) {
			$key = self::column($m[2]);
			$value = trim((string)($row[$key] ?? ''));
			if ($value === '') {
				return '';
			}
			return $m[1] . ($html ? htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value);
		}, $template);
		return (string)$out;
	}
}
