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

	public function testSizeOfTheAttachments(): void {
		$this->assertSame(25, ServerConfigService::attachmentsMb(''), 'the usual limit of Exchange by default');
		$this->assertSame(35, ServerConfigService::attachmentsMb(' 35 '));
		$this->assertSame(150, ServerConfigService::attachmentsMb('150'));
		foreach (['0', '151', '-3', '20.5', 'abc'] as $wrong) {
			$this->assertSame(25, ServerConfigService::attachmentsMb($wrong), $wrong);
		}
	}

	public function testVisioAddress(): void {
		$this->assertSame('', ServerConfigService::visioUrl('  '));
		// Element Call: its address, links made from it
		$this->assertSame('https://call.element.io', ServerConfigService::visioUrl(' https://call.element.io/ '));
		$this->assertSame('https://call.example.org/element', ServerConfigService::visioUrl('https://call.example.org/element/'));
		// another service: the room name in its links
		$this->assertSame('https://meet.example.org/{room}', ServerConfigService::visioUrl('https://meet.example.org/{room}'));
	}

	/** @return array<string, array{0: string}> */
	public static function badVisio(): array {
		return [
			'http' => ['http://call.element.io'],
			'javascript' => ['javascript:alert(1)//{room}'],
			'no host' => ['https:///{room}'],
			'quote' => ['https://call.element.io/"onmouseover=x'],
			'space' => ['https://call.element.io/a b'],
			'backslash' => ['https://call.element.io\\@evil.example'],
			'user' => ['https://me:pw@call.element.io'],
		];
	}

	/** @dataProvider badVisio */
	#[\PHPUnit\Framework\Attributes\DataProvider('badVisio')]
	public function testBadVisio(string $url): void {
		$this->expectException(MailException::class);
		ServerConfigService::visioUrl($url);
	}
}
