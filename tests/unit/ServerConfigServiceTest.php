<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\ServerConfigService;
use PHPUnit\Framework\TestCase;

class ServerConfigServiceTest extends TestCase {
	public function testNormalizeHostOnly(): void {
		$this->assertSame('https://ex3.mail.ovh.net/EWS/Exchange.asmx', ServerConfigService::normalizeUrl('ex3.mail.ovh.net'));
		$this->assertSame('https://ex3.mail.ovh.net/EWS/Exchange.asmx', ServerConfigService::normalizeUrl(' https://EX3.mail.ovh.net/ '));
		$this->assertSame('https://ex5.mail.ovh.net/EWS/Exchange.asmx', ServerConfigService::normalizeUrl('https://ex5.mail.ovh.net/EWS/Exchange.asmx'));
	}

	public function testRejectsHttp(): void {
		$this->expectException(MailException::class);
		ServerConfigService::normalizeUrl('http://ex3.mail.ovh.net');
	}
}
