<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;

class EwsSharedMailboxTest extends EwsTestCase {
	private static function folderIds(array $ids): string {
		$out = '<m:GetFolderResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"><m:ResponseMessages>';
		foreach ($ids as $id) {
			$out .= $id === null
				? '<m:GetFolderResponseMessage ResponseClass="Error"><m:MessageText>x</m:MessageText><m:ResponseCode>ErrorAccessDenied</m:ResponseCode></m:GetFolderResponseMessage>'
				: '<m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="' . $id . '"/></t:Folder></m:Folders></m:GetFolderResponseMessage>';
		}
		return $out . '</m:ResponseMessages></m:GetFolderResponse>';
	}

	public function testFoldersOfASharedMailbox(): void {
		$this->queue(self::folderIds(['S-INBOX', 'S-DRAFTS', 'S-SENT', 'S-DEL', 'S-JUNK', 'S-OUT', 'S-ROOT']));
		$this->queue(self::ok('FindFolder', '<m:RootFolder TotalItemsInView="1" IncludesLastItemInRange="true"><t:Folders>'
			. '<t:Folder><t:FolderId Id="S-INBOX"/><t:ParentFolderId Id="S-ROOT"/><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Boîte de réception</t:DisplayName><t:TotalCount>3</t:TotalCount><t:ChildFolderCount>0</t:ChildFolderCount><t:UnreadCount>2</t:UnreadCount></t:Folder>'
			. '</t:Folders></m:RootFolder>'));
		$folders = $this->client()->listFolders($used, 'contact@example.fr');
		$this->assertSame([['id' => 'S-INBOX', 'name' => 'Boîte de réception', 'parentId' => null, 'total' => 3, 'unread' => 2, 'role' => 'inbox']], $folders);
		$this->assertStringContainsString('<t:DistinguishedFolderId Id="inbox"><t:Mailbox><t:EmailAddress>contact@example.fr</t:EmailAddress></t:Mailbox></t:DistinguishedFolderId>', $this->requests[0]);
		$this->assertStringContainsString('<m:ParentFolderIds><t:DistinguishedFolderId Id="msgfolderroot"><t:Mailbox><t:EmailAddress>contact@example.fr</t:EmailAddress></t:Mailbox></t:DistinguishedFolderId></m:ParentFolderIds>', $this->lastRequest());
	}

	public function testNoAccess(): void {
		$this->queue(self::folderIds(array_fill(0, 7, null)));
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('Vous n\'avez pas accès à la boîte contact@example.fr');
		$this->client()->listFolders($used, 'contact@example.fr');
	}

	public function testSendAsTheSharedMailbox(): void {
		$this->queue(self::ok('CreateItem', '<m:Items/>'));
		$this->client()->sendMessage('new', 'Devis', '<p>x</p>', ['client@x.fr'], [], [], [], null, 'Normal', ['from' => 'contact@example.fr']);
		$this->assertStringContainsString('<t:ToRecipients><t:Mailbox><t:EmailAddress>client@x.fr</t:EmailAddress></t:Mailbox></t:ToRecipients>'
			. '<t:From><t:Mailbox><t:EmailAddress>contact@example.fr</t:EmailAddress></t:Mailbox></t:From>', $this->lastRequest());
		// the next message is from the account again
		$this->queue(self::ok('CreateItem', '<m:Items/>'));
		$this->client()->sendMessage('new', 'Devis', '<p>x</p>', ['client@x.fr'], [], [], []);
		$this->assertStringNotContainsString('<t:From>', $this->lastRequest());
	}

	public function testReplyAsTheSharedMailbox(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:Message><t:ItemId Id="M1" ChangeKey="CK1"/></t:Message></m:Items>'));
		$this->queue(self::ok('CreateItem', '<m:Items/>'));
		$this->client()->sendMessage('reply', '', '<p>Oui</p>', ['a@x.fr'], [], [], [], 'M1', 'Normal', ['from' => 'contact@example.fr']);
		$this->assertStringContainsString('<t:From><t:Mailbox><t:EmailAddress>contact@example.fr</t:EmailAddress></t:Mailbox></t:From><t:ReferenceItemId Id="M1" ChangeKey="CK1"/>', $this->lastRequest());
	}

	public function testDraftSentAsTheSharedMailbox(): void {
		$this->queue(self::ok('UpdateItem', '<m:Items/>'));
		$this->client()->setSendOptions('D1', ['from' => 'contact@example.fr']);
		$this->assertStringContainsString('<t:SetItemField><t:FieldURI FieldURI="message:From"/><t:Message><t:From><t:Mailbox><t:EmailAddress>contact@example.fr</t:EmailAddress></t:Mailbox></t:From></t:Message></t:SetItemField>', $this->lastRequest());
	}
}
