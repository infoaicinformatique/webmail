<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\ShareLinkService;
use PHPUnit\Framework\TestCase;

class ShareProtectionTest extends TestCase {
	public function testProtection(): void {
		$today = new \DateTimeImmutable('2026-11-14T10:00:00Z');
		$this->assertSame(['expires' => null, 'password' => false], ShareLinkService::protection('', false, $today));
		$this->assertSame(['expires' => '2026-11-21', 'password' => true], ShareLinkService::protection(' 2026-11-21 ', true, $today));
		foreach (['2026-11-14', '2027-11-15', '2026-02-30', '21/11/2026'] as $bad) {
			try {
				ShareLinkService::protection($bad, false, $today);
				$this->fail($bad);
			} catch (MailException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testPasswords(): void {
		$a = ShareLinkService::newPassword();
		$this->assertMatchesRegularExpression('/^[a-km-zA-HJ-NP-Z2-9]{12}$/', $a);
		$this->assertNotSame($a, ShareLinkService::newPassword());
	}
}
