<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;

class EmlExportTest extends EwsTestCase {
	public function testMessageAsEml(): void {
		$mime = "From: Jean Dupont <jean@x.fr>\r\nSubject: Devis\r\n\r\nBonjour";
		$this->queue(self::ok('GetItem', '<m:Items><t:Message><t:MimeContent CharacterSet="UTF-8">' . base64_encode($mime) . '</t:MimeContent>'
			. '<t:ItemId Id="M1" ChangeKey="CK"/><t:Subject>Re: Devis n°42 / chantier « Antibes »</t:Subject></t:Message></m:Items>'));
		$eml = $this->client()->getMime('M1');
		$this->assertSame($mime, $eml['content']);
		$this->assertSame('message/rfc822', $eml['contentType']);
		// a file name: no « / », nor reserved characters
		$this->assertSame('Re Devis n°42 chantier « Antibes ».eml', $eml['name']);
		$this->assertStringNotContainsString('/', $eml['name']);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:BaseShape>IdOnly</t:BaseShape><t:IncludeMimeContent>true</t:IncludeMimeContent>', $req);
		$this->assertStringContainsString('<t:ItemId Id="M1"/>', $req);
	}

	public function testNoSubject(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:Message><t:MimeContent CharacterSet="UTF-8">' . base64_encode('x') . '</t:MimeContent>'
			. '<t:ItemId Id="M1" ChangeKey="CK"/></t:Message></m:Items>'));
		$this->assertSame('message.eml', $this->client()->getMime('M1')['name']);
	}

	public function testGone(): void {
		$this->queue('<m:GetItemResponseMessage ResponseClass="Error" xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages">'
			. '<m:MessageText>The specified object was not found in the store.</m:MessageText><m:ResponseCode>ErrorItemNotFound</m:ResponseCode>'
			. '<m:DescriptiveLinkKey>0</m:DescriptiveLinkKey></m:GetItemResponseMessage>');
		$this->expectException(MailException::class);
		$this->client()->getMime('M1');
	}
}
