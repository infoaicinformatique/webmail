<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * A small PDF of text (A4, Helvetica, the accents of Western languages): enough to keep a
 * message as a document, without a library. Lines are wrapped at the width of the page,
 * pages added as needed.
 */
class SimplePdf {
	private const W = 595.28;
	private const H = 841.89;
	private const MARGIN = 56.0;

	/** widths of Helvetica (1/1000 em), ASCII 32-126 */
	private const WIDTHS = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
		556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
		1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
		667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
		333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
		556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];

	/** @var list<list<array{x: float, y: float, size: float, bold: bool, text: string, gray: bool}>> */
	private array $pages = [[]];
	private float $y;

	public function __construct(private string $title = '') {
		$this->y = self::H - self::MARGIN;
	}

	/** Width of a text (UTF-8) in points; a letter with an accent is as wide as without. */
	public static function width(string $text, float $size, bool $bold = false): float {
		$plain = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
		$plain = $plain === false ? $text : $plain;
		$w = 0;
		$len = strlen($plain);
		for ($i = 0; $i < $len; $i++) {
			$c = ord($plain[$i]);
			$w += ($c >= 32 && $c <= 126) ? self::WIDTHS[$c - 32] : 556;
		}
		return $w * $size / 1000 * ($bold ? 1.06 : 1.0);
	}

	/**
	 * Lines of at most $max points, cut between words (a word longer than a line is cut).
	 * @return list<string>
	 */
	public static function wrap(string $text, float $max, float $size, bool $bold = false): array {
		$out = [];
		foreach (preg_split('/\R/u', $text) ?: [''] as $para) {
			$line = '';
			foreach (preg_split('/(?<=\s)/u', $para) ?: [] as $word) {
				if (self::width($line . $word, $size, $bold) <= $max) {
					$line .= $word;
					continue;
				}
				if (trim($line) !== '') {
					$out[] = rtrim($line);
					$line = '';
				}
				// a word wider than the line: cut in pieces
				while (self::width($word, $size, $bold) > $max) {
					$n = max(1, mb_strlen($word) - 1);
					while ($n > 1 && self::width(mb_substr($word, 0, $n), $size, $bold) > $max) {
						$n--;
					}
					$out[] = mb_substr($word, 0, $n);
					$word = mb_substr($word, $n);
				}
				$line = ltrim($word);
			}
			$out[] = rtrim($line);
		}
		return $out;
	}

	/** Some text, wrapped, after what was written before. */
	public function text(string $text, float $size = 10, bool $bold = false, bool $gray = false, float $indent = 0): void {
		$lead = $size * 1.35;
		foreach (self::wrap($text, self::W - 2 * self::MARGIN - $indent, $size, $bold) as $line) {
			if ($this->y - $lead < self::MARGIN) {
				$this->pages[] = [];
				$this->y = self::H - self::MARGIN;
			}
			$this->y -= $lead;
			if ($line !== '') {
				$this->pages[count($this->pages) - 1][] = ['x' => self::MARGIN + $indent, 'y' => $this->y, 'size' => $size, 'bold' => $bold, 'text' => $line, 'gray' => $gray];
			}
		}
	}

	public function space(float $points = 6): void {
		$this->y -= $points;
	}

	/** A thin line across the page. */
	public function rule(): void {
		$this->space(4);
		$this->pages[count($this->pages) - 1][] = ['x' => self::MARGIN, 'y' => $this->y, 'size' => 0, 'bold' => false, 'text' => '', 'gray' => true];
		$this->space(8);
	}

	private static function pdfString(string $text): string {
		$enc = iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $text);
		$enc = $enc === false ? $text : $enc;
		return '(' . strtr($enc, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']) . ')';
	}

	/** The document, as bytes. */
	public function output(): string {
		$objects = [];
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$objects[5] = '<< /Title ' . self::pdfString($this->title) . ' /Producer (Webmail Exchange) /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>';
		$kids = [];
		$n = 6;
		$total = count($this->pages);
		foreach ($this->pages as $p => $items) {
			$stream = '';
			foreach ($items as $it) {
				if ($it['size'] == 0) {
					$stream .= sprintf("0.75 G 0.5 w %.2F %.2F m %.2F %.2F l S\n", $it['x'], $it['y'], self::W - self::MARGIN, $it['y']);
					continue;
				}
				$stream .= sprintf("BT %s /%s %.1F Tf %.2F %.2F Td %s Tj ET\n", $it['gray'] ? '0.4 g' : '0 g', $it['bold'] ? 'F2' : 'F1', $it['size'], $it['x'], $it['y'], self::pdfString($it['text']));
			}
			if ($total > 1) {
				$stream .= sprintf("BT 0.5 g /F1 8 Tf %.2F %.2F Td %s Tj ET\n", self::W / 2 - 10, self::MARGIN / 2, self::pdfString(($p + 1) . ' / ' . $total));
			}
			$content = $n + 1;
			$objects[$n] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $content . ' 0 R >>';
			$objects[$content] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
			$kids[] = $n . ' 0 R';
			$n += 2;
		}
		$objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
		ksort($objects);
		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objects as $id => $body) {
			$offsets[$id] = strlen($pdf);
			$pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen($pdf);
		$count = max(array_keys($objects)) + 1;
		$pdf .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
		for ($i = 1; $i < $count; $i++) {
			$pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
		}
		return $pdf . "trailer\n<< /Size " . $count . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
	}
}
