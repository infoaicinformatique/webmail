<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class SignatureTest extends TestCase {
	public function testPlainTextSignatureKept(): void {
		$this->assertSame("Jean Dupont\nSociété A — 04 93 00 00 00", HtmlSanitizer::signature("  Jean Dupont\nSociété A — 04 93 00 00 00 \n"));
		$this->assertFalse(HtmlSanitizer::isHtml('Prix < 5 € & > 2 €'));
	}

	public function testFormattedSignatureCleaned(): void {
		$sig = HtmlSanitizer::signature('<div><b>Jean Dupont</b></div><div onclick="alert(1)">Société A</div>'
			. '<script>alert(2)</script><a href="https://societe-a.fr">societe-a.fr</a><a href="javascript:alert(3)">x</a>'
			. '<img src="data:image/png;base64,iVBORw0KGgo=" alt="logo"><img src="data:image/svg+xml;base64,PHN2Zz4=">');
		$this->assertStringContainsString('<b>Jean Dupont</b>', $sig);
		$this->assertStringContainsString('<a href="https://societe-a.fr" target="_blank" rel="noopener noreferrer">societe-a.fr</a>', $sig);
		$this->assertStringContainsString('src="data:image/png;base64,iVBORw0KGgo="', $sig);
		foreach (['onclick', '<script', 'javascript:', 'svg+xml'] as $bad) {
			$this->assertStringNotContainsString($bad, $sig);
		}
	}

	public function testEmptyEditorIsNoSignature(): void {
		$this->assertSame('', HtmlSanitizer::signature('<div><br></div>'));
		$this->assertSame('', HtmlSanitizer::signature(''));
		// a logo alone is a signature
		$this->assertStringContainsString('<img', HtmlSanitizer::signature('<div><img src="data:image/png;base64,iVBORw0KGgo="></div>'));
	}

	public function testTooHeavy(): void {
		$this->expectException(MailException::class);
		HtmlSanitizer::signature('<div><img src="data:image/png;base64,' . str_repeat('A', HtmlSanitizer::MAX_SIGNATURE_BYTES) . '"></div>');
	}
}
