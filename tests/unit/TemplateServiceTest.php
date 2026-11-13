<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\TemplateService;
use PHPUnit\Framework\TestCase;

class TemplateServiceTest extends TestCase {
	public function testAddedSortedAndChanged(): void {
		$list = TemplateService::put([], null, 'Relance devis', '<p>Bonjour, avez-vous pu consulter notre devis ?</p>', 'A');
		$list = TemplateService::put($list, null, '  Accusé   de réception ', '<p>Bien reçu, merci.</p>', 'B');
		$this->assertSame(['Accusé de réception', 'Relance devis'], array_column($list, 'name'), 'sorted by name, spaces tidied');
		$list = TemplateService::put($list, 'A', 'Relance du devis', '<p>Bonjour, une relance.</p>', 'unused');
		$this->assertSame(['B', 'A'], array_column($list, 'id'));
		$this->assertSame('<p>Bonjour, une relance.</p>', $list[1]['html']);
		$this->assertSame(['B'], array_column(TemplateService::remove($list, 'A'), 'id'));
	}

	public function testCleanedLikeASignature(): void {
		$t = TemplateService::put([], null, 'Piège', '<p onclick="x()">Bonjour</p><script>alert(1)</script><!--><img src=x onerror=alert(1)>-->', 'A')[0];
		$this->assertSame('<p>Bonjour</p>', $t['html']);
		// plain text becomes HTML, an image alone is a template too
		$this->assertStringContainsString('Ligne 1<br>', TemplateService::put([], null, 'Texte', "Ligne 1\nLigne 2", 'A')[0]['html']);
		$this->assertStringContainsString('<img', TemplateService::put([], null, 'Logo', '<img src="data:image/png;base64,iVBORw0KGgo=">', 'A')[0]['html']);
	}

	public function testRefused(): void {
		$bad = [
			['', '<p>x</p>'],
			[str_repeat('n', TemplateService::MAX_NAME + 1), '<p>x</p>'],
			['Vide', '<div><br></div>'],
			['Lourd', '<p>' . str_repeat('x', TemplateService::MAX_BYTES) . '</p>'],
		];
		foreach ($bad as [$name, $html]) {
			try {
				TemplateService::put([], null, $name, $html, 'A');
				$this->fail('refused: ' . substr($name, 0, 10));
			} catch (MailException) {
				$this->addToAssertionCount(1);
			}
		}
		$full = array_map(fn ($i) => ['id' => 'T' . $i, 'name' => 'M' . $i, 'html' => '<p>x</p>'], range(1, TemplateService::MAX_TEMPLATES));
		$this->expectException(MailException::class);
		TemplateService::put($full, null, 'Un de trop', '<p>x</p>', 'N');
	}

	public function testUnknownTemplate(): void {
		$this->expectException(MailException::class);
		TemplateService::put([], 'nope', 'Nom', '<p>x</p>', 'A');
	}

	public function testStoredStateReadSafely(): void {
		$this->assertSame([], TemplateService::decode('not json'));
		$this->assertSame([['id' => 'A', 'name' => 'N', 'html' => '<p>x</p>']],
			TemplateService::decode('[{"id":"A","name":"N","html":"<p>x</p>"},{"id":3},"x"]'));
	}
}
