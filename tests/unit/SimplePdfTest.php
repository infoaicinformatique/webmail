<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\SimplePdf;
use PHPUnit\Framework\TestCase;

class SimplePdfTest extends TestCase {
	public function testLinesAreWrappedAtTheWidth(): void {
		$lines = SimplePdf::wrap(str_repeat('mot ', 60), 200, 10);
		$this->assertGreaterThan(1, count($lines));
		foreach ($lines as $line) {
			$this->assertLessThanOrEqual(200, SimplePdf::width($line, 10));
		}
		$this->assertSame(['a', '', 'b'], SimplePdf::wrap("a\n\nb", 200, 10), 'the empty lines are kept');
		$long = SimplePdf::wrap(str_repeat('x', 300), 100, 10);
		$this->assertGreaterThan(1, count($long), 'a word too long is cut');
	}

	public function testAValidDocumentWithItsAccentsAndPages(): void {
		$pdf = new SimplePdf('Été');
		$pdf->text('Devis (été) \\ œuvre', 14, true);
		for ($i = 0; $i < 100; $i++) {
			$pdf->text('Ligne ' . $i);
		}
		$out = $pdf->output();
		$this->assertStringStartsWith('%PDF-1.4', $out);
		$this->assertStringEndsWith("%%EOF\n", $out);
		$this->assertStringContainsString('(Devis \\(' . "\xE9" . 't' . "\xE9" . '\\) \\\\ ' . "\x9C" . 'uvre) Tj', $out, 'CP1252, parentheses and backslash escaped');
		$this->assertMatchesRegularExpression('#/Count 2 >>#', $out, 'two pages');
		// the cross-reference table points at each object
		preg_match('/startxref\n(\d+)/', $out, $m);
		$this->assertSame('xref', substr($out, (int)$m[1], 4));
		preg_match_all('/^(\d{10}) 00000 n $/m', $out, $offsets);
		foreach ($offsets[1] as $i => $off) {
			$this->assertSame(($i + 1) . ' 0 obj', substr($out, (int)$off, strlen(($i + 1) . ' 0 obj')));
		}
	}
}
