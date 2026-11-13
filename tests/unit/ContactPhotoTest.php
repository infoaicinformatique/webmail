<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsContacts;
use OCA\Webmail\Exception\MailException;

class ContactPhotoTest extends EwsTestCase {
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
	private const NS = 'xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"';

	private function contacts(): EwsContacts {
		return new EwsContacts($this->client());
	}

	private function attachments(string $xml): string {
		return self::ok('GetItem', '<m:Items><t:Contact><t:ItemId Id="C1" ChangeKey="K"/><t:Attachments>' . $xml . '</t:Attachments></t:Contact></m:Items>');
	}

	public function testPhotoOfAContact(): void {
		$this->queue($this->attachments('<t:FileAttachment><t:AttachmentId Id="A0"/><t:Name>devis.pdf</t:Name><t:IsContactPhoto>false</t:IsContactPhoto></t:FileAttachment>'
			. '<t:FileAttachment><t:AttachmentId Id="A1"/><t:Name>ContactPicture.jpg</t:Name><t:IsContactPhoto>true</t:IsContactPhoto></t:FileAttachment>'));
		$this->queue(self::ok('GetAttachment', '<m:Attachments><t:FileAttachment><t:AttachmentId Id="A1"/><t:Name>ContactPicture.jpg</t:Name>'
			. '<t:ContentType>image/png</t:ContentType><t:Content>' . self::PNG . '</t:Content></t:FileAttachment></m:Attachments>'));
		$photo = $this->contacts()->contactPhoto('C1');
		$this->assertSame(['contentType' => 'image/png', 'content' => base64_decode(self::PNG)], $photo);
		$this->assertStringContainsString('<t:AttachmentId Id="A1"/>', $this->lastRequest());
	}

	public function testPhotoTypeComesFromTheImage(): void {
		// a contact whose « photo » says text/html (anyone who can write the contact chooses it)
		$photo = fn (string $type, string $content) => [
			$this->attachments('<t:FileAttachment><t:AttachmentId Id="A1"/><t:Name>ContactPicture.jpg</t:Name><t:IsContactPhoto>true</t:IsContactPhoto></t:FileAttachment>'),
			self::ok('GetAttachment', '<m:Attachments><t:FileAttachment><t:AttachmentId Id="A1"/><t:Name>ContactPicture.jpg</t:Name>'
				. '<t:ContentType>' . $type . '</t:ContentType><t:Content>' . base64_encode($content) . '</t:Content></t:FileAttachment></m:Attachments>'),
		];
		array_map(fn ($r) => $this->queue($r), $photo('text/html', '<script>alert(1)</script>'));
		$this->assertNull($this->contacts()->contactPhoto('C1'), 'not an image: no photo');
		array_map(fn ($r) => $this->queue($r), $photo('text/html', base64_decode(self::PNG)));
		$this->assertSame('image/png', $this->contacts()->contactPhoto('C1')['contentType'], 'the type of the image, not the declared one');
	}

	public function testNoPhoto(): void {
		$this->queue($this->attachments(''));
		$this->assertNull($this->contacts()->contactPhoto('C1'));
	}

	public function testReplaceThePhoto(): void {
		$this->queue($this->attachments('<t:FileAttachment><t:AttachmentId Id="OLD"/><t:Name>ContactPicture.jpg</t:Name><t:IsContactPhoto>true</t:IsContactPhoto></t:FileAttachment>'));
		$this->queue(self::ok('DeleteAttachment', '<m:RootItemId RootItemId="C1" RootItemChangeKey="K2"/>'));
		$this->queue(self::ok('CreateAttachment', '<m:Attachments><t:FileAttachment><t:AttachmentId Id="NEW" RootItemId="C1" RootItemChangeKey="K3"/></t:FileAttachment></m:Attachments>'));
		$this->contacts()->setContactPhoto('C1', base64_decode(self::PNG));
		$this->assertStringContainsString('<t:AttachmentId Id="OLD"/>', $this->requests[1]);
		$this->assertStringContainsString('<t:Name>ContactPicture.jpg</t:Name><t:ContentType>image/png</t:ContentType><t:IsContactPhoto>true</t:IsContactPhoto><t:Content>' . self::PNG, $this->requests[2]);
	}

	public function testNotAnImage(): void {
		$this->expectException(MailException::class);
		$this->contacts()->setContactPhoto('C1', '<svg onload="alert(1)"/>');
	}

	public function testPhotoOfADirectoryPerson(): void {
		$this->queue('<m:GetUserPhotoResponse ResponseClass="Success" ' . self::NS . '><m:ResponseCode>NoError</m:ResponseCode>'
			. '<m:HasChanged>true</m:HasChanged><m:PictureData>' . self::PNG . '</m:PictureData></m:GetUserPhotoResponse>');
		$this->assertSame('image/png', $this->contacts()->userPhoto('marie@x.fr')['contentType']);
		$this->assertStringContainsString('<m:GetUserPhoto><m:Email>marie@x.fr</m:Email><m:SizeRequested>HR96x96</m:SizeRequested></m:GetUserPhoto>', $this->lastRequest());
		// none
		$this->queue('<m:GetUserPhotoResponse ResponseClass="Error" ' . self::NS . '><m:MessageText>x</m:MessageText><m:ResponseCode>ErrorItemNotFound</m:ResponseCode><m:DescriptiveLinkKey>0</m:DescriptiveLinkKey></m:GetUserPhotoResponse>');
		$this->assertNull($this->contacts()->userPhoto('paul@x.fr'));
		$this->assertNull($this->contacts()->userPhoto('pas une adresse'));
	}
}
