<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\Matrix;
use PHPUnit\Framework\TestCase;

class MatrixTest extends TestCase {
	public function testHomeserver(): void {
		$this->assertSame('https://matrix.example.org', Matrix::homeserver(' matrix.example.org '));
		$this->assertSame('https://matrix.example.org:8448', Matrix::homeserver('https://Matrix.Example.org:8448/'));
		foreach (['http://matrix.example.org', 'https://matrix.example.org/_matrix', 'https://u:p@matrix.example.org', 'https://matrix.example.org?x=1', ''] as $bad) {
			try {
				Matrix::homeserver($bad);
				$this->fail($bad);
			} catch (MailException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testRoom(): void {
		$this->assertSame(['type' => 'alias', 'value' => '#equipe:example.org'], Matrix::room(' #equipe:example.org '));
		$this->assertSame(['type' => 'id', 'value' => '!AbC123:example.org'], Matrix::room('!AbC123:example.org'));
		$this->assertSame(['type' => 'alias', 'value' => '#chantier-antibes:matrix.example.org'], Matrix::room('https://matrix.to/#/%23chantier-antibes:matrix.example.org'));
		$this->assertSame('#equipe:example.org', Matrix::room('https://matrix.to/#/#equipe:example.org?via=example.org')['value']);
		foreach (['equipe', '@alice:example.org', '#equipe', '#a b:example.org', '!x:'] as $bad) {
			try {
				Matrix::room($bad);
				$this->fail($bad);
			} catch (MailException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testMessage(): void {
		$m = Matrix::message("  ✉️ Devis <urgent>\r\nVoir https://cloud.example.org/x?a=1&b=2  ");
		$this->assertSame('m.text', $m['msgtype']);
		$this->assertSame("✉️ Devis <urgent>\nVoir https://cloud.example.org/x?a=1&b=2", $m['body']);
		$this->assertSame('org.matrix.custom.html', $m['format']);
		// nothing of the text is taken as HTML; the link is clickable
		$this->assertSame('✉️ Devis &lt;urgent&gt;<br>' . "\n" . 'Voir <a href="https://cloud.example.org/x?a=1&amp;b=2">https://cloud.example.org/x?a=1&amp;b=2</a>', $m['formatted_body']);
		$this->assertSame(Matrix::MAX_TEXT, mb_strlen(Matrix::message(str_repeat('é', Matrix::MAX_TEXT + 50))['body']));
		$this->expectException(MailException::class);
		Matrix::message("  \n ");
	}

	public function testTxnId(): void {
		$a = Matrix::txnId('alice', '!r:x', 'Bonjour', 100);
		$this->assertSame($a, Matrix::txnId('alice', '!r:x', 'Bonjour', 100));
		$this->assertNotSame($a, Matrix::txnId('alice', '!r:x', 'Bonjour', 101));
		$this->assertMatchesRegularExpression('/^webmail-[0-9a-f]{24}$/', $a);
	}
}
