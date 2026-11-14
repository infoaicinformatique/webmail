<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\PresenceService;
use PHPUnit\Framework\TestCase;

class PresenceTest extends TestCase {
	public function testStatusShown(): void {
		$this->assertSame('online', PresenceService::status('online'));
		$this->assertSame('away', PresenceService::status('away'));
		$this->assertSame('dnd', PresenceService::status('dnd'));
		$this->assertSame('dnd', PresenceService::status('busy'));
		// invisible: shown as off line, as Nextcloud does to the others
		$this->assertSame('offline', PresenceService::status('invisible'));
		$this->assertSame('offline', PresenceService::status('offline'));
		$this->assertSame('offline', PresenceService::status(''));
	}

	public function testAddressesAsked(): void {
		$this->assertSame(['marie@exemple.fr', 'paul@exemple.fr'],
			PresenceService::addresses([' Marie@Exemple.fr ', 'marie@exemple.fr', 'pas une adresse', 42, null, 'paul@exemple.fr']));
		$many = array_map(fn ($i) => 'u' . $i . '@x.fr', range(1, PresenceService::MAX + 10));
		$this->assertCount(PresenceService::MAX, PresenceService::addresses($many));
	}
}
