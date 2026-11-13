<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;

class ConversationListTest extends EwsTestCase {
	private const NS = 'xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"';

	public function testConversationsOfAFolder(): void {
		$this->queue('<m:FindConversationResponse ResponseClass="Success" ' . self::NS . '><m:ResponseCode>NoError</m:ResponseCode><m:Conversations>'
			. '<t:Conversation><t:ConversationId Id="CV1"/><t:ConversationTopic>Devis cuisine</t:ConversationTopic>'
			. '<t:UniqueRecipients><t:String>Utilisateur</t:String></t:UniqueRecipients>'
			. '<t:UniqueSenders><t:String>Claire Leroux</t:String><t:String>Utilisateur</t:String></t:UniqueSenders>'
			. '<t:LastDeliveryTime>2026-11-12T09:30:00Z</t:LastDeliveryTime><t:HasAttachments>true</t:HasAttachments>'
			. '<t:MessageCount>3</t:MessageCount><t:UnreadCount>1</t:UnreadCount>'
			. '<t:ItemIds><t:ItemId Id="M3" ChangeKey="K3"/><t:ItemId Id="M1" ChangeKey="K1"/></t:ItemIds></t:Conversation>'
			. '<t:Conversation><t:ConversationId Id="CV2"/><t:ConversationTopic>Facture</t:ConversationTopic>'
			. '<t:UniqueSenders><t:String>OVHcloud</t:String></t:UniqueSenders><t:LastDeliveryTime>2026-11-11T08:00:00Z</t:LastDeliveryTime>'
			. '<t:MessageCount>1</t:MessageCount><t:UnreadCount>0</t:UnreadCount><t:ItemIds><t:ItemId Id="M9" ChangeKey="K9"/></t:ItemIds></t:Conversation>'
			. '</m:Conversations><m:TotalConversationsInView>60</m:TotalConversationsInView><m:IndexedOffset>2</m:IndexedOffset></m:FindConversationResponse>');
		$r = $this->client()->findConversations('inbox', 0, 2);
		$this->assertSame(60, $r['total']);
		$this->assertTrue($r['hasMore']);
		[$a, $b] = $r['items'];
		$this->assertSame('M3', $a['id']);
		$this->assertSame('CV1', $a['conversationId']);
		$this->assertSame('Claire Leroux, Utilisateur', $a['from']['name']);
		$this->assertSame(3, $a['count']);
		$this->assertSame(1, $a['unreadCount']);
		$this->assertFalse($a['isRead']);
		$this->assertTrue($a['hasAttachments']);
		$this->assertSame(['M3', 'M1'], $a['ids']);
		$this->assertTrue($b['isRead']);
		$this->assertSame(1, $b['count']);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<m:IndexedPageItemView MaxEntriesReturned="2" Offset="0" BasePoint="Beginning"/><m:ParentFolderId><t:DistinguishedFolderId Id="inbox"/></m:ParentFolderId>', $req);
	}

	public function testNotSupported(): void {
		$this->queue('<m:FindConversationResponse ResponseClass="Error" ' . self::NS . '><m:MessageText>x</m:MessageText><m:ResponseCode>ErrorInvalidOperation</m:ResponseCode><m:DescriptiveLinkKey>0</m:DescriptiveLinkKey></m:FindConversationResponse>');
		$this->expectException(MailException::class);
		$this->client()->findConversations('F1');
	}
}
