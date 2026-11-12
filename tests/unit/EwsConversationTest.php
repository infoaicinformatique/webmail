<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

class EwsConversationTest extends EwsTestCase {
	private static function msg(string $id, string $folder, string $date, string $from, bool $read = true): string {
		return '<t:Message><t:ItemId Id="' . $id . '" ChangeKey="CK"/><t:ParentFolderId Id="' . $folder . '"/><t:Subject>RE : Devis</t:Subject>'
			. '<t:DateTimeReceived>' . $date . '</t:DateTimeReceived><t:HasAttachments>false</t:HasAttachments><t:Importance>Normal</t:Importance>'
			. '<t:Preview>…</t:Preview><t:IsRead>' . ($read ? 'true' : 'false') . '</t:IsRead>'
			. '<t:From><t:Mailbox><t:Name>' . $from . '</t:Name><t:EmailAddress>x@y.fr</t:EmailAddress></t:Mailbox></t:From></t:Message>';
	}

	public function testEveryFolderOldestFirst(): void {
		$this->queue(self::ok('GetConversationItems', '<m:Conversation><t:ConversationId Id="CONV1"/><t:ConversationNodes>'
			. '<t:ConversationNode><t:InternetMessageId>&lt;a@x&gt;</t:InternetMessageId><t:Items>' . self::msg('M3', 'INBOX', '2026-11-12T10:00:00Z', 'Jean', false) . '</t:Items></t:ConversationNode>'
			. '<t:ConversationNode><t:InternetMessageId>&lt;b@x&gt;</t:InternetMessageId><t:Items>' . self::msg('M1', 'INBOX', '2026-11-10T08:00:00Z', 'Jean') . '</t:Items></t:ConversationNode>'
			. '<t:ConversationNode><t:InternetMessageId>&lt;c@x&gt;</t:InternetMessageId><t:Items>' . self::msg('M2', 'SENT', '2026-11-11T09:00:00Z', 'Moi') . '</t:Items></t:ConversationNode>'
			. '</t:ConversationNodes></m:Conversation>'));
		$items = $this->client()->conversation('CONV1');
		$this->assertSame(['M1', 'M2', 'M3'], array_column($items, 'id'));
		$this->assertSame(['INBOX', 'SENT', 'INBOX'], array_column($items, 'folderId'));
		$this->assertFalse($items[2]['isRead']);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<m:FoldersToIgnore><t:DistinguishedFolderId Id="deleteditems"/><t:DistinguishedFolderId Id="drafts"/></m:FoldersToIgnore>', $req);
		$this->assertStringContainsString('<m:Conversations><t:Conversation><t:ConversationId Id="CONV1"/></t:Conversation></m:Conversations>', $req);
	}

	public function testMessageKnowsItsConversation(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:Message><t:ItemId Id="M1" ChangeKey="CK"/><t:Subject>Devis</t:Subject>'
			. '<t:Body BodyType="HTML">&lt;p&gt;x&lt;/p&gt;</t:Body><t:ConversationId Id="CONV1"/></t:Message></m:Items>'));
		$this->assertSame('CONV1', $this->client()->getItem('M1')['conversationId']);
		$this->assertStringContainsString('<t:FieldURI FieldURI="item:ConversationId"/>', $this->lastRequest());
	}
}
