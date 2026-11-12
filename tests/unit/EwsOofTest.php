<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsOof;
use OCA\Webmail\Exception\MailException;

class EwsOofTest extends EwsTestCase {
	private const NS = 'xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"';

	private function oof(): EwsOof {
		return new EwsOof($this->client(), 'user@example.fr');
	}

	private function settings(string $state, string $extra = ''): string {
		return '<m:GetUserOofSettingsResponse ' . self::NS . '><m:ResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode></m:ResponseMessage>'
			. '<t:OofSettings><t:OofState>' . $state . '</t:OofState><t:ExternalAudience>Known</t:ExternalAudience>'
			. '<t:Duration><t:StartTime>2026-11-20T08:00:00Z</t:StartTime><t:EndTime>2026-11-30T18:00:00Z</t:EndTime></t:Duration>'
			. '<t:InternalReply><t:Message>&lt;html&gt;&lt;body&gt;&lt;div&gt;Je suis absent jusqu\'au 30.&lt;br&gt;Merci de contacter Paul.&lt;/div&gt;&lt;/body&gt;&lt;/html&gt;</t:Message></t:InternalReply>'
			. '<t:ExternalReply><t:Message></t:Message></t:ExternalReply>' . $extra
			. '</t:OofSettings><m:AllowExternalOof>All</m:AllowExternalOof></m:GetUserOofSettingsResponse>';
	}

	public function testGetScheduled(): void {
		$this->queue($this->settings('Scheduled'));
		$r = $this->oof()->get(new \DateTimeImmutable('2026-11-25T10:00:00Z'));
		$this->assertSame([
			'state' => 'Scheduled',
			'audience' => 'Known',
			'start' => '2026-11-20T08:00:00Z',
			'end' => '2026-11-30T18:00:00Z',
			'internal' => "Je suis absent jusqu'au 30.\nMerci de contacter Paul.",
			'external' => '',
			'active' => true,
		], $r);
		$this->assertStringContainsString('<m:GetUserOofSettingsRequest><t:Mailbox><t:Address>user@example.fr</t:Address></t:Mailbox></m:GetUserOofSettingsRequest>', $this->lastRequest());
	}

	public function testScheduledButNotYet(): void {
		$this->queue($this->settings('Scheduled'));
		$this->assertFalse($this->oof()->get(new \DateTimeImmutable('2026-11-12T10:00:00Z'))['active']);
	}

	public function testSetScheduled(): void {
		$this->queue('<m:SetUserOofSettingsResponse ' . self::NS . '><m:ResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode></m:ResponseMessage></m:SetUserOofSettingsResponse>');
		$this->oof()->set(['state' => 'Scheduled', 'audience' => 'All', 'start' => '2026-11-20T09:00:00+01:00', 'end' => '2026-11-30T19:00:00+01:00',
			'internal' => "Absent.\nContact : Paul", 'external' => '']);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:OofState>Scheduled</t:OofState><t:ExternalAudience>All</t:ExternalAudience>'
			. '<t:Duration><t:StartTime>2026-11-20T08:00:00Z</t:StartTime><t:EndTime>2026-11-30T18:00:00Z</t:EndTime></t:Duration>', $req);
		$this->assertStringContainsString("Absent.&lt;br&gt;\nContact : Paul", $req);
		// no separate external message: the internal one is used
		$this->assertSame(2, substr_count($req, "Absent.&lt;br&gt;\nContact : Paul"));
	}

	public function testSetRejectsBadPeriod(): void {
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('après son début');
		$this->oof()->set(['state' => 'Scheduled', 'start' => '2026-11-30T09:00:00Z', 'end' => '2026-11-20T09:00:00Z', 'internal' => 'x']);
	}

	public function testSetNeedsAMessage(): void {
		$this->expectException(MailException::class);
		$this->oof()->set(['state' => 'Enabled', 'internal' => '  ']);
	}

	public function testErrorIsReported(): void {
		$this->queue('<m:SetUserOofSettingsResponse ' . self::NS . '><m:ResponseMessage ResponseClass="Error"><m:MessageText>x</m:MessageText><m:ResponseCode>ErrorAccessDenied</m:ResponseCode></m:ResponseMessage></m:SetUserOofSettingsResponse>');
		$this->expectException(MailException::class);
		$this->oof()->set(['state' => 'Disabled']);
	}

	public function testHtmlToTextKeepsTheLinesAsTyped(): void {
		// what textToHtml() sends, read back from Exchange
		$html = \OCA\Webmail\Service\HtmlSanitizer::textToHtml("Bonjour,\nje suis absent.\n\nÀ bientôt");
		$this->assertSame("Bonjour,\nje suis absent.\n\nÀ bientôt", EwsOof::htmlToText($html));
		$this->assertSame("Absent du 1er au 5\nContact : Paul", EwsOof::htmlToText("<html><body><p>Absent du 1er\n au 5</p><div>Contact&nbsp;: Paul</div></body></html>"));
	}
}
