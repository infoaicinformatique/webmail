<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\TrustedImagesService;
use PHPUnit\Framework\TestCase;

class TrustedImagesTest extends TestCase {
	public function testAddressesAndDomains(): void {
		$list = TrustedImagesService::with([], ' News@Exemple.org ');
		$list = TrustedImagesService::with($list, '@Client-Exemple.fr');
		$list = TrustedImagesService::with($list, 'news@exemple.org'); // already there
		$this->assertSame(['@client-exemple.fr', 'news@exemple.org'], $list);

		$this->assertTrue(TrustedImagesService::matches($list, 'NEWS@exemple.org'));
		$this->assertTrue(TrustedImagesService::matches($list, 'jean.dupont@client-exemple.fr'));
		// another address of the same domain, a sub-domain, a look-alike: no
		$this->assertFalse(TrustedImagesService::matches($list, 'promo@exemple.org'));
		$this->assertFalse(TrustedImagesService::matches($list, 'x@mail.client-exemple.fr'));
		$this->assertFalse(TrustedImagesService::matches($list, 'x@client-exemple.fr.evil.example'));
		$this->assertFalse(TrustedImagesService::matches($list, ''));
		$this->assertFalse(TrustedImagesService::matches($list, '@client-exemple.fr'));
	}

	/** @return array<string, array{0: string}> */
	public static function badEntries(): array {
		return [
			'empty' => [''],
			'no domain' => ['news@'],
			'domain without dot' => ['@localhost'],
			'spaces' => ['@exem ple.org'],
			'two at' => ['a@b@c.org'],
			'just text' => ['exemple.org'],
		];
	}

	/** @dataProvider badEntries */
	#[\PHPUnit\Framework\Attributes\DataProvider('badEntries')]
	public function testBadEntries(string $entry): void {
		$this->expectException(MailException::class);
		TrustedImagesService::with([], $entry);
	}

	public function testDecodeKeepsOnlyGoodEntries(): void {
		$this->assertSame(['news@exemple.org', '@exemple.fr'],
			TrustedImagesService::decode('["news@exemple.org", "NOT LOWER@x.org", "@exemple.fr", 5, "javascript:x"]'));
		$this->assertSame([], TrustedImagesService::decode('oops'));
	}

	public function testAtMost(): void {
		$list = array_map(fn ($i) => 'a' . $i . '@x.org', range(1, TrustedImagesService::MAX));
		$this->expectException(MailException::class);
		TrustedImagesService::with($list, 'one-more@x.org');
	}
}
