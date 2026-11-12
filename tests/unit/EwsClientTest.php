<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Exception\MailException;

/**
 * Every request produced by the client is validated against Microsoft's
 * Exchange2013_SP1 EWS schema (tests/fixtures/xsd), which is the version
 * announced in RequestServerVersion. Responses are canned from the EWS docs.
 */
class EwsClientTest extends EwsTestCase {
	public function testListFolders(): void {
		$this->queue('<m:GetFolderResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"><m:ResponseMessages>'
			. '<m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="INBOX-ID" ChangeKey="a"/></t:Folder></m:Folders></m:GetFolderResponseMessage>'
			. '<m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="DRAFTS-ID" ChangeKey="a"/></t:Folder></m:Folders></m:GetFolderResponseMessage>'
			. '<m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="SENT-ID" ChangeKey="a"/></t:Folder></m:Folders></m:GetFolderResponseMessage>'
			. '<m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="DEL-ID" ChangeKey="a"/></t:Folder></m:Folders></m:GetFolderResponseMessage>'
			. '<m:GetFolderResponseMessage ResponseClass="Error"><m:MessageText>not found</m:MessageText><m:ResponseCode>ErrorFolderNotFound</m:ResponseCode></m:GetFolderResponseMessage>'
			. '<m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="ROOT-ID" ChangeKey="a"/></t:Folder></m:Folders></m:GetFolderResponseMessage>'
			. '</m:ResponseMessages></m:GetFolderResponse>');
		$this->queue(self::ok('FindFolder', '<m:RootFolder TotalItemsInView="4" IncludesLastItemInRange="true"><t:Folders>'
			. '<t:Folder><t:FolderId Id="INBOX-ID"/><t:ParentFolderId Id="ROOT-ID"/><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Boîte de réception</t:DisplayName><t:TotalCount>12</t:TotalCount><t:ChildFolderCount>1</t:ChildFolderCount><t:UnreadCount>3</t:UnreadCount><t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0xe08" PropertyType="Long"/><t:Value>1000000</t:Value></t:ExtendedProperty></t:Folder>'
			. '<t:Folder><t:FolderId Id="SUB-ID"/><t:ParentFolderId Id="INBOX-ID"/><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Clients</t:DisplayName><t:TotalCount>2</t:TotalCount><t:ChildFolderCount>0</t:ChildFolderCount><t:UnreadCount>0</t:UnreadCount></t:Folder>'
			. '<t:CalendarFolder><t:FolderId Id="CAL-ID"/><t:ParentFolderId Id="ROOT-ID"/><t:FolderClass>IPF.Appointment</t:FolderClass><t:DisplayName>Calendrier</t:DisplayName><t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0xe08" PropertyType="Long"/><t:Value>250000</t:Value></t:ExtendedProperty></t:CalendarFolder>'
			. '<t:Folder><t:FolderId Id="SENT-ID"/><t:ParentFolderId Id="ROOT-ID"/><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Éléments envoyés</t:DisplayName><t:TotalCount>5</t:TotalCount><t:UnreadCount>0</t:UnreadCount></t:Folder>'
			. '</t:Folders></m:RootFolder>'));

		$used = null;
		$folders = $this->client()->listFolders($used);

		$this->assertCount(3, $folders);
		$this->assertSame(1250000, $used, 'all folders count, calendars and contacts too');
		$this->assertStringContainsString('<t:ExtendedFieldURI PropertyTag="0x0E08" PropertyType="Long"/>', $this->requests[1]);
		$this->assertSame(['id' => 'INBOX-ID', 'name' => 'Boîte de réception', 'parentId' => null, 'total' => 12, 'unread' => 3, 'role' => 'inbox'], $folders[0]);
		$this->assertSame('INBOX-ID', $folders[1]['parentId']);
		$this->assertNull($folders[1]['role']);
		$this->assertSame('sentitems', $folders[2]['role']);
		$this->assertStringContainsString('Traversal="Deep"', $this->requests[1]);
	}

	public function testMailboxQuotaPrefersTheSendLimit(): void {
		$prop = fn (string $tag, int $kb) => '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="' . $tag . '" PropertyType="Integer"/><t:Value>' . $kb . '</t:Value></t:ExtendedProperty>';
		$this->queue('<m:GetFolderResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"><m:ResponseMessages>'
			. '<m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="TOP"/>'
			. $prop('0x666a', 52428800) . $prop('0x666e', 51200000) . '</t:Folder></m:Folders></m:GetFolderResponseMessage>'
			. '<m:GetFolderResponseMessage ResponseClass="Error"><m:MessageText>x</m:MessageText><m:ResponseCode>ErrorAccessDenied</m:ResponseCode></m:GetFolderResponseMessage>'
			. '</m:ResponseMessages></m:GetFolderResponse>');
		$this->assertSame(51200000 * 1024, $this->client()->mailboxQuota());
		$this->assertStringContainsString('<m:FolderIds><t:DistinguishedFolderId Id="msgfolderroot"/><t:DistinguishedFolderId Id="root"/></m:FolderIds>', $this->requests[0]);
	}

	public function testMailboxQuotaUnknown(): void {
		$this->queue(self::ok('GetFolder', '<m:Folders><t:Folder><t:FolderId Id="TOP"/></t:Folder></m:Folders>'));
		$this->assertNull($this->client()->mailboxQuota());
	}

	public function testSearchQueryCombinesTextAndFilters(): void {
		$this->assertNull(EwsClient::searchQuery('  ', []));
		$this->assertSame('devis', EwsClient::searchQuery(' devis ', ['unread' => false]));
		$this->assertSame('devis isread:false hasattachment:true isflagged:true received>=2026-10-01',
			EwsClient::searchQuery('devis', ['unread' => true, 'attachments' => true, 'flagged' => true, 'since' => '2026-10-01']));
		$this->assertSame('isread:false', EwsClient::searchQuery('', ['unread' => true]), 'a filter alone is a search');
	}

	public function testSearchQueryRejectsBadDates(): void {
		$this->expectException(MailException::class);
		EwsClient::searchQuery('', ['since' => '2026-13-45']);
	}

	public function testSearchAllFoldersMergesNewestFirst(): void {
		$msg = fn (string $id, string $folder, string $date) => '<t:Message><t:ItemId Id="' . $id . '" ChangeKey="c"/><t:ParentFolderId Id="' . $folder . '"/>'
			. '<t:Subject>S ' . $id . '</t:Subject><t:DateTimeReceived>' . $date . '</t:DateTimeReceived><t:IsRead>true</t:IsRead></t:Message>';
		$found = fn (int $total, bool $last, string $items) => '<m:FindItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode>'
			. '<m:RootFolder IndexedPagingOffset="0" TotalItemsInView="' . $total . '" IncludesLastItemInRange="' . ($last ? 'true' : 'false') . '"><t:Items>' . $items . '</t:Items></m:RootFolder></m:FindItemResponseMessage>';
		$this->queue('<m:FindItemResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"><m:ResponseMessages>'
			. $found(2, true, $msg('A', 'INBOX', '2026-11-10T08:00:00Z') . $msg('B', 'INBOX', '2026-11-01T08:00:00Z'))
			. $found(1, true, $msg('C', 'CLIENTS', '2026-11-05T08:00:00Z'))
			. '</m:ResponseMessages></m:FindItemResponse>');
		$r = $this->client()->searchFolders(['INBOX', 'CLIENTS'], 0, 2, 'devis');
		$this->assertSame(['A', 'C'], array_column($r['items'], 'id'));
		$this->assertSame(['INBOX', 'CLIENTS'], array_column($r['items'], 'folderId'));
		$this->assertSame(3, $r['total']);
		$this->assertTrue($r['hasMore']);
		$req = $this->requests[0];
		$this->assertStringContainsString('<m:ParentFolderIds><t:FolderId Id="INBOX"/><t:FolderId Id="CLIENTS"/></m:ParentFolderIds><m:QueryString>devis</m:QueryString>', $req);
		$this->assertStringContainsString('<t:FieldURI FieldURI="item:ParentFolderId"/>', $req);
		$this->assertStringContainsString('MaxEntriesReturned="2"', $req);
	}

	public function testSearchAllFoldersReportsAnError(): void {
		$this->queue('<m:FindItemResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages"><m:ResponseMessages>'
			. '<m:FindItemResponseMessage ResponseClass="Error"><m:MessageText>x</m:MessageText><m:ResponseCode>ErrorAccessDenied</m:ResponseCode></m:FindItemResponseMessage>'
			. '</m:ResponseMessages></m:FindItemResponse>');
		$this->expectException(MailException::class);
		$this->client()->searchFolders(['INBOX'], 0, 50, 'x');
	}

	private function inboxUnread(int $n): string {
		return self::ok('GetFolder', '<m:Folders><t:Folder><t:FolderId Id="INBOX"/><t:UnreadCount>' . $n . '</t:UnreadCount></t:Folder></m:Folders>');
	}

	public function testNewMessagesWithoutDateGivesTheStartingPoint(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="60" IncludesLastItemInRange="false"><t:Items>'
			. '<t:Message><t:ItemId Id="M1" ChangeKey="c"/><t:Subject>Old</t:Subject><t:DateTimeReceived>2026-11-12T08:00:00Z</t:DateTimeReceived><t:IsRead>false</t:IsRead></t:Message>'
			. '</t:Items></m:RootFolder>'));
		$this->queue($this->inboxUnread(4));
		$r = $this->client()->newMessages(null);
		// the page learns the newest messages (not announced) and where to start from
		$this->assertSame(['M1'], array_column($r['items'], 'id'));
		$this->assertSame('2026-11-12T08:00:00Z', $r['latest']);
		$this->assertSame(4, $r['unread']);
		$this->assertStringNotContainsString('Restriction', $this->requests[0]);
	}

	public function testNewMessagesSince(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="2" IncludesLastItemInRange="true"><t:Items>'
			. '<t:Message><t:ItemId Id="N2" ChangeKey="c"/><t:Subject>Deux</t:Subject><t:DateTimeReceived>2026-11-12T09:05:00Z</t:DateTimeReceived><t:IsRead>false</t:IsRead></t:Message>'
			. '<t:Message><t:ItemId Id="N1" ChangeKey="c"/><t:Subject>Un</t:Subject><t:DateTimeReceived>2026-11-12T09:01:00Z</t:DateTimeReceived><t:IsRead>false</t:IsRead></t:Message>'
			. '</t:Items></m:RootFolder>'));
		$this->queue($this->inboxUnread(6));
		$r = $this->client()->newMessages('2026-11-12T10:00:00+01:00');
		$this->assertSame(['N2', 'N1'], array_column($r['items'], 'id'));
		$this->assertSame('2026-11-12T09:05:00Z', $r['latest']);
		$this->assertSame(6, $r['unread']);
		$this->assertStringContainsString('<t:IsGreaterThanOrEqualTo><t:FieldURI FieldURI="item:DateTimeReceived"/><t:FieldURIOrConstant><t:Constant Value="2026-11-12T09:00:00Z"/></t:FieldURIOrConstant></t:IsGreaterThanOrEqualTo>', $this->requests[0]);
		$this->assertStringContainsString('<t:FieldURI FieldURI="message:IsRead"/><t:FieldURIOrConstant><t:Constant Value="false"/>', $this->requests[0]);
	}

	public function testNewMessagesNothingNew(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="0" IncludesLastItemInRange="true"><t:Items/></m:RootFolder>'));
		$this->queue($this->inboxUnread(0));
		$this->assertSame('2026-11-12T09:00:00Z', $this->client()->newMessages('2026-11-12T09:00:00Z')['latest']);
	}

	private function created(string $id): string {
		return self::ok('CreateItem', '<m:Items><t:Message><t:ItemId Id="' . $id . '" ChangeKey="CK-' . $id . '"/></t:Message></m:Items>');
	}

	public function testSaveNewDraft(): void {
		$this->queue($this->created('D1'));
		$id = $this->client()->saveDraft(null, 'new', 'Devis', '<p>Bonjour</p>', ['a@x.fr'], [], ['b@x.fr'], null, 'High');
		$this->assertSame('D1', $id);
		$this->assertCount(1, $this->requests);
		$req = $this->requests[0];
		$this->assertStringContainsString('<m:CreateItem MessageDisposition="SaveOnly"><m:SavedItemFolderId><t:DistinguishedFolderId Id="drafts"/>', $req);
		$this->assertStringContainsString('<t:Body BodyType="HTML">&lt;p&gt;Bonjour&lt;/p&gt;</t:Body>', $req);
		$this->assertStringContainsString('<t:Importance>High</t:Importance>', $req);
		$this->assertStringContainsString('<t:BccRecipients><t:Mailbox><t:EmailAddress>b@x.fr</t:EmailAddress></t:Mailbox></t:BccRecipients>', $req);
	}

	public function testSaveReplyDraftReplacesThePreviousOne(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:Message><t:ItemId Id="ORIG" ChangeKey="CK-O"/></t:Message></m:Items>'));
		$this->queue($this->created('D2'));
		$this->queue(self::ok('DeleteItem'));
		$id = $this->client()->saveDraft('D1', 'reply', '', '<p>Oui</p>', [], [], [], 'ORIG');
		$this->assertSame('D2', $id);
		$this->assertStringContainsString('<t:ReplyToItem><t:ReferenceItemId Id="ORIG" ChangeKey="CK-O"/><t:NewBodyContent BodyType="HTML">', $this->requests[1]);
		$this->assertStringContainsString('MessageDisposition="SaveOnly"', $this->requests[1]);
		$this->assertStringContainsString('<m:DeleteItem DeleteType="HardDelete"><m:ItemIds><t:ItemId Id="D1"/>', $this->requests[2]);
	}

	public function testUpdateDraft(): void {
		$this->queue(self::ok('UpdateItem', '<m:Items><t:Message><t:ItemId Id="D1" ChangeKey="CK2"/></t:Message></m:Items>'));
		$this->client()->updateDraft('D1', 'Objet', '<p>Texte</p>', ['a@x.fr'], [], [], 'Low');
		$req = $this->requests[0];
		$this->assertStringContainsString('<m:UpdateItem MessageDisposition="SaveOnly" ConflictResolution="AlwaysOverwrite">', $req);
		$this->assertStringContainsString('<t:SetItemField><t:FieldURI FieldURI="message:ToRecipients"/><t:Message><t:ToRecipients><t:Mailbox><t:EmailAddress>a@x.fr</t:EmailAddress>', $req);
		$this->assertStringContainsString('<t:DeleteItemField><t:FieldURI FieldURI="message:CcRecipients"/></t:DeleteItemField>', $req);
		$this->assertStringContainsString('<t:SetItemField><t:FieldURI FieldURI="item:Body"/><t:Message><t:Body BodyType="HTML">&lt;p&gt;Texte&lt;/p&gt;</t:Body></t:Message></t:SetItemField>', $req);
		$this->assertStringContainsString('<t:Importance>Low</t:Importance>', $req);
	}

	public function testSendSavedDraft(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:Message><t:ItemId Id="D1" ChangeKey="CK3"/></t:Message></m:Items>'));
		$this->queue(self::ok('SendItem'));
		$this->client()->sendSavedDraft('D1');
		$this->assertStringContainsString('<m:SendItem SaveItemToFolder="true"><m:ItemIds><t:ItemId Id="D1" ChangeKey="CK3"/></m:ItemIds>', $this->requests[1]);
	}

	private function roles(): string {
		$ok = fn (string $id) => '<m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="' . $id . '"/></t:Folder></m:Folders></m:GetFolderResponseMessage>';
		return '<m:GetFolderResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"><m:ResponseMessages>'
			. $ok('INBOX') . $ok('DRAFTS') . $ok('SENT') . $ok('DELETED') . $ok('JUNK') . $ok('OUTBOX') . $ok('ROOT')
			. '</m:ResponseMessages></m:GetFolderResponse>';
	}

	public function testCreateFolder(): void {
		$this->queue(self::ok('CreateFolder', '<m:Folders><t:Folder><t:FolderId Id="NEW" ChangeKey="c"/></t:Folder></m:Folders>'));
		$this->assertSame('NEW', $this->client()->createFolder(' Projets 2027 ', 'INBOX'));
		$this->assertStringContainsString('<m:CreateFolder><m:ParentFolderId><t:FolderId Id="INBOX"/></m:ParentFolderId><m:Folders><t:Folder><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Projets 2027</t:DisplayName>', $this->requests[0]);
		$this->queue(self::ok('CreateFolder', '<m:Folders><t:Folder><t:FolderId Id="TOP" ChangeKey="c"/></t:Folder></m:Folders>'));
		$this->client()->createFolder('Archives');
		$this->assertStringContainsString('<m:ParentFolderId><t:DistinguishedFolderId Id="msgfolderroot"/></m:ParentFolderId>', $this->lastRequest());
	}

	public function testFolderNameIsChecked(): void {
		$this->expectException(MailException::class);
		$this->client()->createFolder('   ');
	}

	public function testRenameMoveDeleteFolder(): void {
		$this->queue($this->roles());
		$this->queue(self::ok('UpdateFolder', '<m:Folders><t:Folder><t:FolderId Id="F1"/></t:Folder></m:Folders>'));
		$this->client()->renameFolder('F1', 'Clients 2027');
		$this->assertStringContainsString('<t:FolderChange><t:FolderId Id="F1"/><t:Updates><t:SetFolderField><t:FieldURI FieldURI="folder:DisplayName"/><t:Folder><t:DisplayName>Clients 2027</t:DisplayName>', $this->lastRequest());
		$this->queue($this->roles());
		$this->queue(self::ok('MoveFolder', '<m:Folders><t:Folder><t:FolderId Id="F1"/></t:Folder></m:Folders>'));
		$this->client()->moveFolder('F1', null);
		$this->assertStringContainsString('<m:MoveFolder><m:ToFolderId><t:DistinguishedFolderId Id="msgfolderroot"/></m:ToFolderId><m:FolderIds><t:FolderId Id="F1"/>', $this->lastRequest());
		$this->queue($this->roles());
		$this->queue(self::ok('DeleteFolder'));
		$this->client()->deleteFolder('F1');
		$this->assertStringContainsString('<m:DeleteFolder DeleteType="MoveToDeletedItems"><m:FolderIds><t:FolderId Id="F1"/>', $this->lastRequest());
	}

	public function testExchangeFoldersAreProtected(): void {
		$this->queue($this->roles());
		try {
			$this->client()->deleteFolder('SENT');
			$this->fail('the Sent Items folder must not be deleted');
		} catch (MailException $e) {
			$this->assertStringContainsString('indispensable', $e->getMessage());
		}
		$this->assertCount(1, $this->requests, 'nothing sent besides the check');
		$this->expectException(MailException::class);
		$this->client()->renameFolder('inbox', 'x');
	}

	public function testFindItemsWithSearchAndPaging(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder IndexedPagingOffset="50" TotalItemsInView="120" IncludesLastItemInRange="false"><t:Items>'
			. '<t:Message><t:ItemId Id="M1" ChangeKey="CK1"/><t:Subject>Facture &amp; devis</t:Subject><t:DateTimeReceived>2026-11-10T08:15:00Z</t:DateTimeReceived>'
			. '<t:HasAttachments>true</t:HasAttachments><t:Importance>High</t:Importance><t:DisplayTo>Moi</t:DisplayTo><t:Preview>Bonjour, ci-joint</t:Preview>'
			. '<t:Flag><t:FlagStatus>Flagged</t:FlagStatus></t:Flag><t:IsRead>false</t:IsRead>'
			. '<t:From><t:Mailbox><t:Name>Jean Dupont</t:Name><t:EmailAddress>jean@client.fr</t:EmailAddress><t:RoutingType>SMTP</t:RoutingType></t:Mailbox></t:From></t:Message>'
			. '<t:MeetingRequest><t:ItemId Id="M2" ChangeKey="CK2"/><t:Subject>Réunion</t:Subject><t:IsRead>true</t:IsRead></t:MeetingRequest>'
			. '</t:Items></m:RootFolder>'));

		$res = $this->client()->findItems('inbox', 50, 50, 'facture "urgent"');

		$this->assertSame(120, $res['total']);
		$this->assertTrue($res['hasMore']);
		$this->assertCount(2, $res['items']);
		$m = $res['items'][0];
		$this->assertSame('M1', $m['id']);
		$this->assertSame('Facture & devis', $m['subject']);
		$this->assertSame(['name' => 'Jean Dupont', 'email' => 'jean@client.fr'], $m['from']);
		$this->assertFalse($m['isRead']);
		$this->assertTrue($m['hasAttachments']);
		$this->assertTrue($m['flagged']);
		$this->assertSame('High', $m['importance']);
		$this->assertTrue($res['items'][1]['isMeeting']);

		$req = $this->lastRequest();
		$this->assertStringContainsString('Offset="50"', $req);
		$this->assertStringContainsString('<t:DistinguishedFolderId Id="inbox"/>', $req);
		$this->assertStringContainsString('<m:QueryString>facture &quot;urgent&quot;</m:QueryString>', $req);
	}

	public function testFindItemsInCustomFolderEscapesId(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="0" IncludesLastItemInRange="true"><t:Items/></m:RootFolder>'));
		$res = $this->client()->findItems('AAMk+abc/="<x>', 0, 50);
		$this->assertSame([], $res['items']);
		$this->assertFalse($res['hasMore']);
		$this->assertStringContainsString('<t:FolderId Id="AAMk+abc/=&quot;&lt;x&gt;"/>', $this->lastRequest());
	}

	public function testGetItem(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:Message><t:ItemId Id="M1" ChangeKey="CK1"/><t:ParentFolderId Id="INBOX-ID"/>'
			. '<t:Subject>Bonjour</t:Subject><t:Body BodyType="HTML" IsTruncated="false">&lt;html&gt;&lt;body&gt;&lt;p&gt;Salut&lt;/p&gt;&lt;img src="cid:logo@x"&gt;&lt;/body&gt;&lt;/html&gt;</t:Body>'
			. '<t:Attachments>'
			. '<t:FileAttachment><t:AttachmentId Id="A1"/><t:Name>devis.pdf</t:Name><t:ContentType>application/pdf</t:ContentType><t:Size>1234</t:Size><t:IsInline>false</t:IsInline></t:FileAttachment>'
			. '<t:FileAttachment><t:AttachmentId Id="A2"/><t:Name>logo.png</t:Name><t:ContentType>image/png</t:ContentType><t:ContentId>logo@x</t:ContentId><t:Size>99</t:Size><t:IsInline>true</t:IsInline></t:FileAttachment>'
			. '<t:ItemAttachment><t:AttachmentId Id="A3"/><t:Name>Message transféré</t:Name><t:Size>5000</t:Size></t:ItemAttachment>'
			. '</t:Attachments>'
			. '<t:DateTimeReceived>2026-11-10T08:15:00Z</t:DateTimeReceived><t:HasAttachments>true</t:HasAttachments><t:Importance>Normal</t:Importance>'
			. '<t:ToRecipients><t:Mailbox><t:Name>Moi</t:Name><t:EmailAddress>user@example.fr</t:EmailAddress></t:Mailbox><t:Mailbox><t:Name>Paul</t:Name><t:EmailAddress>paul@example.fr</t:EmailAddress></t:Mailbox></t:ToRecipients>'
			. '<t:CcRecipients><t:Mailbox><t:Name>Anne</t:Name><t:EmailAddress>anne@example.fr</t:EmailAddress></t:Mailbox></t:CcRecipients>'
			. '<t:IsRead>false</t:IsRead><t:From><t:Mailbox><t:Name>Jean</t:Name><t:EmailAddress>jean@client.fr</t:EmailAddress></t:Mailbox></t:From>'
			. '<t:InternetMessageId>&lt;abc@client.fr&gt;</t:InternetMessageId></t:Message></m:Items>'));

		$m = $this->client()->getItem('M1');

		$this->assertSame('Bonjour', $m['subject']);
		$this->assertStringContainsString('<p>Salut</p>', $m['body']);
		$this->assertSame('HTML', $m['bodyType']);
		$this->assertCount(2, $m['to']);
		$this->assertSame('anne@example.fr', $m['cc'][0]['email']);
		$this->assertSame('jean@client.fr', $m['from']['email']);
		$this->assertCount(3, $m['attachments']);
		$this->assertSame('logo@x', $m['attachments'][1]['contentId']);
		$this->assertTrue($m['attachments'][1]['isInline']);
		$this->assertTrue($m['attachments'][2]['isItem']);
		$this->assertSame('<abc@client.fr>', $m['messageId']);
		$this->assertStringContainsString('<t:BodyType>HTML</t:BodyType>', $this->lastRequest());
	}

	public function testMeetingRequestReceivedAsDelegate(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:MeetingRequest><t:ItemId Id="MR1"/><t:Subject>Revue trimestrielle</t:Subject>'
			. '<t:Body BodyType="HTML">x</t:Body><t:DateTimeReceived>2026-11-10T08:15:00Z</t:DateTimeReceived><t:IsRead>false</t:IsRead>'
			. '<t:From><t:Mailbox><t:Name>Paul</t:Name><t:EmailAddress>paul@x.fr</t:EmailAddress></t:Mailbox></t:From></t:MeetingRequest></m:Items>'));
		$this->queue(self::ok('GetItem', '<m:Items><t:MeetingRequest><t:ItemId Id="MR1"/>'
			. '<t:ReceivedRepresenting><t:Mailbox><t:Name>Marie Martin</t:Name><t:EmailAddress>marie@x.fr</t:EmailAddress></t:Mailbox></t:ReceivedRepresenting>'
			. '<t:Start>2026-11-20T09:00:00Z</t:Start><t:End>2026-11-20T10:00:00Z</t:End><t:Location>Salle B</t:Location>'
			. '<t:MyResponseType>NoResponseReceived</t:MyResponseType></t:MeetingRequest></m:Items>'));
		$m = $this->client()->getItem('MR1');
		$this->assertSame(['name' => 'Marie Martin', 'email' => 'marie@x.fr'], $m['meeting']['onBehalfOf']);
		$this->assertStringContainsString('<t:FieldURI FieldURI="message:ReceivedRepresenting"/>', $this->lastRequest());
	}

	public function testMeetingRequestForMyselfHasNoDelegation(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:MeetingRequest><t:ItemId Id="MR2"/><t:Subject>x</t:Subject></t:MeetingRequest></m:Items>'));
		$this->queue(self::ok('GetItem', '<m:Items><t:MeetingRequest><t:ItemId Id="MR2"/>'
			. '<t:ReceivedRepresenting><t:Mailbox><t:Name>Moi</t:Name><t:EmailAddress>USER@example.fr</t:EmailAddress></t:Mailbox></t:ReceivedRepresenting>'
			. '<t:Start>2026-11-20T09:00:00Z</t:Start><t:End>2026-11-20T10:00:00Z</t:End></t:MeetingRequest></m:Items>'));
		$this->assertNull($this->client()->getItem('MR2')['meeting']['onBehalfOf']);
	}

	public function testGetAttachment(): void {
		$this->queue(self::ok('GetAttachment', '<m:Attachments><t:FileAttachment><t:AttachmentId Id="A1"/><t:Name>devis.pdf</t:Name>'
			. '<t:ContentType>application/pdf</t:ContentType><t:Content>' . base64_encode('%PDF-1.4 test') . '</t:Content></t:FileAttachment></m:Attachments>'));
		$a = $this->client()->getAttachment('A1');
		$this->assertSame(['name' => 'devis.pdf', 'contentType' => 'application/pdf', 'content' => '%PDF-1.4 test'], $a);
	}

	public function testGetItemAttachmentReturnsEml(): void {
		$this->queue(self::ok('GetAttachment', '<m:Attachments><t:ItemAttachment><t:AttachmentId Id="A3"/><t:Name>Message transféré</t:Name>'
			. '<t:Message><t:MimeContent CharacterSet="UTF-8">' . base64_encode("Subject: x\r\n\r\nbody") . '</t:MimeContent></t:Message></t:ItemAttachment></m:Attachments>'));
		$a = $this->client()->getAttachment('A3');
		$this->assertSame('Message transféré.eml', $a['name']);
		$this->assertSame('message/rfc822', $a['contentType']);
		$this->assertSame("Subject: x\r\n\r\nbody", $a['content']);
	}

	public function testSetReadAndFlag(): void {
		$this->queue(self::ok('UpdateItem'));
		$this->queue(self::ok('UpdateItem'));
		$c = $this->client();
		$c->setRead(['M1', 'M2'], true);
		$this->assertStringContainsString('<t:IsRead>true</t:IsRead>', $this->lastRequest());
		$this->assertSame(2, substr_count($this->lastRequest(), '<t:ItemChange>'));
		$c->setFlagged(['M1'], true);
		$this->assertStringContainsString('<t:FlagStatus>Flagged</t:FlagStatus>', $this->lastRequest());
	}

	public function testMoveAndDelete(): void {
		$this->queue(self::ok('MoveItem'));
		$this->queue(self::ok('DeleteItem'));
		$this->queue(self::ok('DeleteItem'));
		$c = $this->client();
		$c->moveItems(['M1'], 'junkemail');
		$this->assertStringContainsString('<m:ToFolderId><t:DistinguishedFolderId Id="junkemail"/>', $this->lastRequest());
		$c->deleteItems(['M1']);
		$this->assertStringContainsString('DeleteType="MoveToDeletedItems"', $this->lastRequest());
		$c->deleteItems(['M1'], true);
		$this->assertStringContainsString('DeleteType="HardDelete"', $this->lastRequest());
	}

	public function testSendNewMessage(): void {
		$this->queue(self::ok('CreateItem', '<m:Items/>'));
		$this->client()->sendMessage('new', 'Objet <test>', '<p>Bonjour</p>', ['a@x.fr', ''], ['b@x.fr'], ['c@x.fr'], [], null, 'High');
		$req = $this->lastRequest();
		$this->assertStringContainsString('MessageDisposition="SendAndSaveCopy"', $req);
		$this->assertStringContainsString('<t:DistinguishedFolderId Id="sentitems"/>', $req);
		$this->assertStringContainsString('<t:Subject>Objet &lt;test&gt;</t:Subject>', $req);
		$this->assertStringContainsString('<t:Importance>High</t:Importance>', $req);
		$this->assertSame(1, substr_count($req, '<t:ToRecipients>'));
		$this->assertStringContainsString('<t:BccRecipients><t:Mailbox><t:EmailAddress>c@x.fr</t:EmailAddress>', $req);
	}

	public function testSendNewMessageWithAttachmentsUsesDraft(): void {
		$this->queue(self::ok('CreateItem', '<m:Items><t:Message><t:ItemId Id="D1" ChangeKey="DCK1"/></t:Message></m:Items>'));
		$this->queue(self::ok('SendItem'));
		$this->client()->sendMessage('new', 'Pièce jointe', '<p>Voir PJ</p>', ['a@x.fr'], [], [],
			[['name' => 'facture.pdf', 'contentType' => 'application/pdf', 'content' => 'PDFDATA']]);

		$this->assertCount(2, $this->requests);
		$this->assertStringContainsString('MessageDisposition="SaveOnly"', $this->requests[0]);
		$this->assertStringContainsString('<t:Content>' . base64_encode('PDFDATA') . '</t:Content>', $this->requests[0]);
		$this->assertStringContainsString('<m:SendItem SaveItemToFolder="true">', $this->requests[1]);
		$this->assertStringContainsString('<t:ItemId Id="D1" ChangeKey="DCK1"/>', $this->requests[1]);
	}

	public function testReplyAll(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:Message><t:ItemId Id="M1" ChangeKey="FRESH"/></t:Message></m:Items>'));
		$this->queue(self::ok('CreateItem', '<m:Items/>'));
		$this->client()->sendMessage('replyAll', '', '<p>Merci</p>', [], ['extra@x.fr'], [], [], 'M1');
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:ReplyAllToItem>', $req);
		$this->assertStringContainsString('<t:ReferenceItemId Id="M1" ChangeKey="FRESH"/>', $req);
		$this->assertStringContainsString('<t:NewBodyContent BodyType="HTML">&lt;p&gt;Merci&lt;/p&gt;</t:NewBodyContent>', $req);
	}

	public function testForwardWithAttachment(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:Message><t:ItemId Id="M1" ChangeKey="CK"/></t:Message></m:Items>'));
		$this->queue(self::ok('CreateItem', '<m:Items><t:Message><t:ItemId Id="D9" ChangeKey="D9CK"/></t:Message></m:Items>'));
		$this->queue(self::ok('CreateAttachment', '<m:Attachments><t:FileAttachment><t:AttachmentId Id="NA" RootItemId="D9" RootItemChangeKey="D9CK2"/></t:FileAttachment></m:Attachments>'));
		$this->queue(self::ok('SendItem'));
		$this->client()->sendMessage('forward', '', '<p>Pour info</p>', ['z@x.fr'], [], [],
			[['name' => 'a.txt', 'contentType' => 'text/plain', 'content' => 'hello']], 'M1');

		$this->assertCount(4, $this->requests);
		$this->assertStringContainsString('<t:ForwardItem><t:ToRecipients>', $this->requests[1]);
		$this->assertStringContainsString('<m:ParentItemId Id="D9"/>', $this->requests[2]);
		$this->assertStringContainsString('<t:ItemId Id="D9" ChangeKey="D9CK2"/>', $this->requests[3]);
	}

	public function testErrorResponseIsTranslated(): void {
		$this->queue('<m:GetItemResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages"><m:ResponseMessages>'
			. '<m:GetItemResponseMessage ResponseClass="Error"><m:MessageText>The specified object was not found in the store.</m:MessageText>'
			. '<m:ResponseCode>ErrorItemNotFound</m:ResponseCode></m:GetItemResponseMessage></m:ResponseMessages></m:GetItemResponse>');
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('Ce message n\'existe plus');
		$this->client()->getItem('gone');
	}

	public function testUnauthorized(): void {
		$this->responses[] = [401, ''];
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('mot de passe Exchange incorrect');
		$this->client()->testConnection();
	}

	public function testSoapFault(): void {
		$this->responses[] = [500, '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault>'
			. '<faultcode>a:ErrorSchemaValidation</faultcode><faultstring>The request failed schema validation.</faultstring></s:Fault></s:Body></s:Envelope>'];
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('schema validation');
		$this->client()->testConnection();
	}

	public function testInvalidXmlCharactersAreStripped(): void {
		$this->queue(self::ok('CreateItem', '<m:Items/>'));
		$this->client()->sendMessage('new', "Bad\x01char", 'x', ['a@x.fr'], [], []);
		$this->assertStringContainsString('<t:Subject>Badchar</t:Subject>', $this->lastRequest());
	}
}
