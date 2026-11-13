<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\HtmlSanitizer;

class InlineImagesTest extends EwsTestCase {
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	public function testPastedImagesBecomeInlineAttachments(): void {
		$html = '<div>Voici la capture :</div><div><img src="data:image/png;base64,' . self::PNG . '" alt="image" style="max-width:100%"></div>'
			. '<p>et la même : <img alt="" src=\'data:image/png;base64,' . self::PNG . '\'></p>'
			. '<p><img src="data:image/jpeg;base64,/9j/4AAQSkZJRg=="></p>';
		$r = HtmlSanitizer::extractInlineImages($html);
		$this->assertCount(2, $r['images'], 'the same image is attached once');
		[$png, $jpg] = $r['images'];
		$this->assertSame('image/png', $png['contentType']);
		$this->assertSame('image-1.png', $png['name']);
		$this->assertSame(base64_decode(self::PNG), $png['content']);
		$this->assertTrue($png['isInline']);
		$this->assertMatchesRegularExpression('/^image-[0-9a-f]{16}@webmail$/', $png['contentId']);
		$this->assertSame('image/jpeg', $jpg['contentType']);
		$this->assertSame('image-2.jpg', $jpg['name']);
		$this->assertStringNotContainsString('data:', $r['html']);
		$this->assertSame(2, substr_count($r['html'], 'src="cid:' . $png['contentId'] . '"') + substr_count($r['html'], "src='cid:" . $png['contentId'] . "'"));
		$this->assertStringContainsString('alt="image" style="max-width:100%"', $r['html']);
	}

	public function testOtherImagesAreLeftAlone(): void {
		$html = '<img src="https://exemple.fr/logo.png"><img src="cid:logo@outlook"><p>data:image/png;base64,AAAA</p>';
		$r = HtmlSanitizer::extractInlineImages($html);
		$this->assertSame([], $r['images']);
		$this->assertSame($html, $r['html']);
	}

	public function testBrokenImageIsDropped(): void {
		$r = HtmlSanitizer::extractInlineImages('<img src="data:image/png;base64,@@@">');
		$this->assertSame([], $r['images']);
		$r = HtmlSanitizer::extractInlineImages('<img src="data:image/png;base64,====">');
		$this->assertSame([], $r['images']);
		$this->assertStringNotContainsString('data:', $r['html']);
	}

	public function testSentWithTheirContentId(): void {
		$r = HtmlSanitizer::extractInlineImages('<p>Capture : <img src="data:image/png;base64,' . self::PNG . '"></p>');
		$this->queue(self::ok('CreateItem', '<m:Items><t:Message><t:ItemId Id="D1" ChangeKey="DCK1"/></t:Message></m:Items>'));
		$this->queue(self::ok('SendItem'));
		$this->client()->sendMessage('new', 'Capture', $r['html'], ['a@x.fr'], [], [], $r['images']);
		$req = $this->requests[0];
		$cid = $r['images'][0]['contentId'];
		$this->assertStringContainsString('<t:FileAttachment><t:Name>image-1.png</t:Name><t:ContentType>image/png</t:ContentType><t:ContentId>' . $cid
			. '</t:ContentId><t:IsInline>true</t:IsInline><t:Content>' . self::PNG . '</t:Content></t:FileAttachment>', $req);
		$this->assertStringContainsString('src=&quot;cid:' . $cid . '&quot;', $req);
	}

	public function testInlineImagesOfAReply(): void {
		$r = HtmlSanitizer::extractInlineImages('<p><img src="data:image/png;base64,' . self::PNG . '"></p>');
		$this->queue(self::ok('GetItem', '<m:Items><t:Message><t:ItemId Id="M1" ChangeKey="CK"/></t:Message></m:Items>'));
		$this->queue(self::ok('CreateItem', '<m:Items><t:Message><t:ItemId Id="D2" ChangeKey="DCK2"/></t:Message></m:Items>'));
		$this->queue(self::ok('CreateAttachment', '<m:Attachments><t:FileAttachment><t:AttachmentId Id="A1" RootItemId="D2" RootItemChangeKey="DCK3"/></t:FileAttachment></m:Attachments>'));
		$this->queue(self::ok('SendItem'));
		$this->client()->sendMessage('reply', '', $r['html'], [], [], [], $r['images'], 'M1');
		$this->assertStringContainsString('<m:CreateAttachment>', $this->requests[2]);
		$this->assertStringContainsString('<t:IsInline>true</t:IsInline>', $this->requests[2]);
		$this->assertStringContainsString('ChangeKey="DCK3"', $this->requests[3]);
	}
}
