<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\MailHeaders;

class MailHeadersTest extends EwsTestCase {
	private const RAW = "Received: from EX3.mail.ovh.net (10.0.0.3) by EX3.mail.ovh.net (10.0.0.3)\r\n"
		. " with Microsoft SMTP Server; Fri, 13 Nov 2026 09:12:05 +0100\r\n"
		. "Received: from mail.client-exemple.fr (mail.client-exemple.fr [203.0.113.7])\r\n"
		. "\tby mx1.mail.ovh.net (Postfix) with ESMTPS id 4A1B2; Fri, 13 Nov 2026 09:12:03 +0100\r\n"
		. "Authentication-Results: mx1.mail.ovh.net; spf=pass smtp.mailfrom=client-exemple.fr;\r\n"
		. " dkim=pass header.d=client-exemple.fr; dmarc=pass header.from=client-exemple.fr\r\n"
		. "Authentication-Results: forged.example; spf=fail; dkim=fail; dmarc=fail\r\n"
		. "From: =?UTF-8?Q?Jean_Dupont_=C3=A0_Nice?= <jean.dupont@client-exemple.fr>\r\n"
		. "Subject: Devis\r\n"
		. "Message-ID: <abc@client-exemple.fr>\r\n";

	public function testHeadersOfAMessage(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:Message><t:ItemId Id="M1" ChangeKey="CK"/><t:ExtendedProperty>'
			. '<t:ExtendedFieldURI PropertyTag="0x7d" PropertyType="String"/><t:Value>' . htmlspecialchars(self::RAW) . '</t:Value>'
			. '</t:ExtendedProperty></t:Message></m:Items>'));
		$r = $this->client()->getHeaders('M1');
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties><t:ExtendedFieldURI PropertyTag="0x007D" PropertyType="String"/>', $req);
		$this->assertStringContainsString('<t:ItemId Id="M1"/>', $req);

		$this->assertCount(7, $r['headers']);
		// a header on several lines is one header
		$this->assertSame('Received', $r['headers'][1]['name']);
		$this->assertSame('from mail.client-exemple.fr (mail.client-exemple.fr [203.0.113.7]) by mx1.mail.ovh.net (Postfix) with ESMTPS id 4A1B2; Fri, 13 Nov 2026 09:12:03 +0100', $r['headers'][1]['value']);
		// encoded words decoded
		$this->assertSame(['name' => 'From', 'value' => 'Jean Dupont à Nice <jean.dupont@client-exemple.fr>'], $r['headers'][4]);
		// the checks of the receiving server, not of a header added on the way
		$this->assertSame(['spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'pass'], $r['auth']);
		// the sender's server first, in UTC
		$this->assertSame([
			['from' => 'mail.client-exemple.fr', 'by' => 'mx1.mail.ovh.net', 'date' => '2026-11-13T08:12:03Z'],
			['from' => 'EX3.mail.ovh.net', 'by' => 'EX3.mail.ovh.net', 'date' => '2026-11-13T08:12:05Z'],
		], $r['hops']);
		$this->assertStringStartsWith('Received: from EX3', $r['raw']);
		$this->assertStringNotContainsString("\r", $r['raw']);
	}

	public function testWrittenInTheMailbox(): void {
		// a draft or a sent message: Exchange has no Internet headers for it
		$this->queue(self::ok('GetItem', '<m:Items><t:Message><t:ItemId Id="M1" ChangeKey="CK"/></t:Message></m:Items>'));
		$r = $this->client()->getHeaders('M1');
		$this->assertSame(['raw' => '', 'headers' => [], 'auth' => [], 'hops' => []], $r);
	}

	public function testReceivedSpfAndFailures(): void {
		$r = MailHeaders::parse("Received-SPF: SoftFail (domain does not designate 198.51.100.9)\nAuthentication-Results: mx; dkim=fail (bad signature); dmarc=fail\nReceived: garbage without date\n");
		$this->assertSame(['dkim' => 'fail', 'dmarc' => 'fail', 'spf' => 'softfail'], $r['auth']);
		$this->assertSame([['from' => '', 'by' => '', 'date' => null]], $r['hops']);
	}

	public function testHugeHeadersAreCut(): void {
		$r = MailHeaders::parse('X-Spam: ' . str_repeat('é', MailHeaders::MAX_BYTES));
		$this->assertLessThanOrEqual(MailHeaders::MAX_BYTES, strlen($r['raw']));
		$this->assertTrue(mb_check_encoding($r['raw'], 'UTF-8'));
	}
}
