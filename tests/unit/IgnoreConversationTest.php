<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

class IgnoreConversationTest extends EwsTestCase {
	private static function done(int $n): string {
		return '<m:ApplyConversationActionResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages"><m:ResponseMessages>'
			. str_repeat('<m:ApplyConversationActionResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode></m:ApplyConversationActionResponseMessage>', $n)
			. '</m:ResponseMessages></m:ApplyConversationActionResponse>';
	}

	public function testIgnore(): void {
		$this->queue(self::done(1));
		$this->client()->ignoreConversation('CONV+1/=', true);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:ConversationAction><t:Action>AlwaysDelete</t:Action><t:ConversationId Id="CONV+1/="/>'
			. '<t:ProcessRightAway>true</t:ProcessRightAway><t:EnableAlwaysDelete>true</t:EnableAlwaysDelete></t:ConversationAction>', $req);
		$this->assertStringNotContainsString('<t:Action>Move</t:Action>', $req);
	}

	public function testNoLongerIgnored(): void {
		$this->queue(self::done(2));
		$this->client()->ignoreConversation('CONV1', false);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:EnableAlwaysDelete>false</t:EnableAlwaysDelete>', $req);
		// what was deleted goes back to the inbox
		$this->assertStringContainsString('<t:Action>Move</t:Action><t:ConversationId Id="CONV1"/>'
			. '<t:ContextFolderId><t:DistinguishedFolderId Id="deleteditems"/></t:ContextFolderId>'
			. '<t:DestinationFolderId><t:DistinguishedFolderId Id="inbox"/></t:DestinationFolderId>', $req);
	}

	public function testRefused(): void {
		$this->queue('<m:ApplyConversationActionResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages"><m:ResponseMessages>'
			. '<m:ApplyConversationActionResponseMessage ResponseClass="Error"><m:MessageText>x</m:MessageText><m:ResponseCode>ErrorApplyConversationActionFailed</m:ResponseCode><m:DescriptiveLinkKey>0</m:DescriptiveLinkKey></m:ApplyConversationActionResponseMessage>'
			. '</m:ResponseMessages></m:ApplyConversationActionResponse>');
		$this->expectException(\OCA\Webmail\Exception\MailException::class);
		$this->client()->ignoreConversation('CONV1', true);
	}
}
