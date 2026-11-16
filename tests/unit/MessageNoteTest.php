<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\MessageNote;
use PHPUnit\Framework\TestCase;

class MessageNoteTest extends TestCase {
	public function testText(): void {
		$html = '<html><head><style>p{}</style><title>x</title></head><body><p>Bonjour,</p><p>Le devis&nbsp;est <b>prêt</b>.<br>Cordialement,</p>'
			. '<script>alert(1)</script><ul><li>un</li><li>deux</li></ul></body></html>';
		$this->assertSame("Bonjour,\nLe devis est prêt.\nCordialement,\nun\ndeux", MessageNote::text($html));
		$this->assertSame('', MessageNote::text('  '));
	}

	public function testFileName(): void {
		$this->assertSame('RE Devis n°42 chantier.md', MessageNote::fileName('RE: Devis n°42 / chantier'));
		$this->assertSame('Message.md', MessageNote::fileName(' ... '));
	}

	public function testMarkdown(): void {
		$md = MessageNote::markdown('Devis toiture', 'Jean Dupont <jean@client.fr>', '13 nov. 2026, 09:12', "# pas un titre\n- ni une liste\nTexte", 'https://cloud.example.org/apps/webmail/?account=3&message=M1');
		$this->assertSame("# Devis toiture\n\n**De :** Jean Dupont <jean@client.fr>  \n**Date :** 13 nov. 2026, 09:12\n\n\\# pas un titre\n\\- ni une liste\nTexte\n\n"
			. "[Ouvrir dans le webmail](https://cloud.example.org/apps/webmail/?account=3&message=M1)\n", $md);
	}
	public function testCardTitle(): void {
		$this->assertSame('Devis 2027 urgent', MessageNote::cardTitle("  Devis\t2027\r\n urgent "));
		$this->assertSame('Message', MessageNote::cardTitle('   '));
		$this->assertSame(255, mb_strlen(MessageNote::cardTitle(str_repeat('é', 300))));
	}
}
