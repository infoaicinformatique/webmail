<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

class DraftAttachmentsTest extends EwsTestCase {
	private function draftWith(string $atts): string {
		return self::ok('GetItem', '<m:Items><t:Message><t:ItemId Id="D1" ChangeKey="C2"/><t:Attachments>' . $atts . '</t:Attachments></t:Message></m:Items>');
	}

	public function testFilesSavedWithTheDraft(): void {
		$this->queue(self::ok('CreateAttachment', '<m:Attachments><t:FileAttachment><t:AttachmentId Id="A1" RootItemId="D1" RootItemChangeKey="C2"/></t:FileAttachment></m:Attachments>'));
		$this->queue($this->draftWith(
			'<t:FileAttachment><t:AttachmentId Id="A1"/><t:Name>devis.pdf</t:Name><t:Size>1234</t:Size><t:IsInline>false</t:IsInline></t:FileAttachment>'
			. '<t:FileAttachment><t:AttachmentId Id="A0"/><t:Name>image-1.png</t:Name><t:Size>99</t:Size><t:IsInline>true</t:IsInline></t:FileAttachment>'));
		$list = $this->client()->addDraftAttachments('D1', [['name' => 'devis.pdf', 'contentType' => 'application/pdf', 'content' => 'PDF']]);
		$this->assertSame([['id' => 'A1', 'name' => 'devis.pdf', 'size' => 1234]], $list, 'the images of the text are not listed');
		$this->assertStringContainsString('<m:CreateAttachment><m:ParentItemId Id="D1"/>', $this->requests[0]);
		$this->assertStringContainsString('<t:FieldURI FieldURI="item:Attachments"/>', $this->requests[1]);
	}

	public function testRemoveAFileOfTheDraft(): void {
		$this->queue(self::ok('DeleteAttachment', '<m:RootItemId RootItemId="D1" RootItemChangeKey="C3"/>'));
		$this->client()->deleteAttachment('A1');
		$this->assertStringContainsString('<m:DeleteAttachment><m:AttachmentIds><t:AttachmentId Id="A1"/></m:AttachmentIds></m:DeleteAttachment>', $this->lastRequest());
	}
}
