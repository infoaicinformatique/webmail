<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\UnifiedSearchService;
use PHPUnit\Framework\TestCase;

class ReferenceLinkTest extends TestCase {
	private const BASE = 'https://cloud.example.org/apps/webmail/';

	public function testLinks(): void {
		$this->assertSame(['account' => 3, 'message' => 'AAMk+a/b=', 'event' => null],
			UnifiedSearchService::parseLink('https://cloud.example.org/apps/webmail/?account=3&message=AAMk%2Ba%2Fb%3D', self::BASE));
		$this->assertSame(['account' => 3, 'message' => null, 'event' => 'EV1'],
			UnifiedSearchService::parseLink('https://CLOUD.example.org/apps/webmail?account=3&module=calendar&event=EV1', self::BASE));
		$this->assertSame(['account' => 7, 'message' => 'M1', 'event' => null],
			UnifiedSearchService::parseLink('http://localhost:8080/index.php/apps/webmail/?account=7&message=M1', 'http://localhost:8080/index.php/apps/webmail/'));
	}

	/** @return array<string, array{0: string}> */
	public static function notOurs(): array {
		return [
			'another host' => ['https://evil.example.org/apps/webmail/?account=3&message=M1'],
			'another port' => ['https://cloud.example.org:8443/apps/webmail/?account=3&message=M1'],
			'another app' => ['https://cloud.example.org/apps/mail/?account=3&message=M1'],
			'no account' => ['https://cloud.example.org/apps/webmail/?message=M1'],
			'account not a number' => ['https://cloud.example.org/apps/webmail/?account=3x&message=M1'],
			'nothing to show' => ['https://cloud.example.org/apps/webmail/?account=3'],
			'both' => ['https://cloud.example.org/apps/webmail/?account=3&message=M1&event=E1'],
			'not a link' => ['Devis toiture'],
		];
	}

	/** @dataProvider notOurs */
	#[\PHPUnit\Framework\Attributes\DataProvider('notOurs')]
	public function testNotOurs(string $url): void {
		$this->assertNull(UnifiedSearchService::parseLink($url, self::BASE));
	}
}
