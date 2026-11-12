<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Exception\MailException;
use PHPUnit\Framework\TestCase;

/**
 * Every request produced by the client is validated against Microsoft's
 * Exchange2013_SP1 EWS schema (tests/fixtures/xsd), which is the version
 * announced in RequestServerVersion. Responses are canned from the EWS docs.
 */
class EwsClientTest extends TestCase {
	/** @var string[] SOAP bodies sent */
	private array $requests = [];
	/** @var array<int, array{0: int, 1: string}> queued responses */
	private array $responses = [];

	private function client(): EwsClient {
		return new EwsClient('https://ex3.mail.ovh.net/EWS/Exchange.asmx', 'user@example.fr', 'secret', 30, true,
			function (string $url, string $body): array {
				$this->assertSame('https://ex3.mail.ovh.net/EWS/Exchange.asmx', $url);
				$this->requests[] = $body;
				$this->assertValidRequest($body);
				return array_shift($this->responses) ?? [200, self::envelope('')];
			});
	}

	private function queue(string $bodyXml, int $status = 200): void {
		$this->responses[] = [$status, self::envelope($bodyXml)];
	}

	private static function envelope(string $body): string {
		return '<?xml version="1.0" encoding="utf-8"?>'
			. '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<s:Header><h:ServerVersionInfo MajorVersion="15" MinorVersion="1" MajorBuildNumber="2507" MinorBuildNumber="6" xmlns:h="http://schemas.microsoft.com/exchange/services/2006/types"/></s:Header>'
			. '<s:Body xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">'
			. $body . '</s:Body></s:Envelope>';
	}

	private static function ok(string $operation, string $inner = ''): string {
		return '<m:' . $operation . 'Response xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types">'
			. '<m:ResponseMessages><m:' . $operation . 'ResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode>'
			. $inner . '</m:' . $operation . 'ResponseMessage></m:ResponseMessages></m:' . $operation . 'Response>';
	}

	private function assertValidRequest(string $envelope): void {
		$doc = new \DOMDocument();
		$this->assertTrue($doc->loadXML($envelope), 'request is well-formed XML');
		$xp = new \DOMXPath($doc);
		$xp->registerNamespace('soap', EwsClient::NS_SOAP);
		$xp->registerNamespace('t', EwsClient::NS_T);
		$this->assertSame('Exchange2013_SP1', $xp->query('//soap:Header/t:RequestServerVersion/@Version')->item(0)?->nodeValue);

		$bodyEl = $xp->query('//soap:Body/*')->item(0);
		$this->assertNotNull($bodyEl);
		$single = new \DOMDocument();
		$single->appendChild($single->importNode($bodyEl, true));
		$single->loadXML($single->saveXML()); // re-parse so namespaces are declared on the root

		$prev = libxml_use_internal_errors(true);
		$valid = $single->schemaValidate(__DIR__ . '/../fixtures/xsd/messages.xsd');
		$errors = array_map(fn ($e) => trim($e->message), libxml_get_errors());
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$this->assertTrue($valid, "EWS request does not match the schema:\n" . implode("\n", $errors) . "\n" . $single->saveXML());
	}

	private function lastRequest(): string {
		return (string)end($this->requests);
	}

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
			. '<t:Folder><t:FolderId Id="INBOX-ID"/><t:ParentFolderId Id="ROOT-ID"/><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Boîte de réception</t:DisplayName><t:TotalCount>12</t:TotalCount><t:ChildFolderCount>1</t:ChildFolderCount><t:UnreadCount>3</t:UnreadCount></t:Folder>'
			. '<t:Folder><t:FolderId Id="SUB-ID"/><t:ParentFolderId Id="INBOX-ID"/><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Clients</t:DisplayName><t:TotalCount>2</t:TotalCount><t:ChildFolderCount>0</t:ChildFolderCount><t:UnreadCount>0</t:UnreadCount></t:Folder>'
			. '<t:CalendarFolder><t:FolderId Id="CAL-ID"/><t:ParentFolderId Id="ROOT-ID"/><t:FolderClass>IPF.Appointment</t:FolderClass><t:DisplayName>Calendrier</t:DisplayName></t:CalendarFolder>'
			. '<t:Folder><t:FolderId Id="SENT-ID"/><t:ParentFolderId Id="ROOT-ID"/><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Éléments envoyés</t:DisplayName><t:TotalCount>5</t:TotalCount><t:UnreadCount>0</t:UnreadCount></t:Folder>'
			. '</t:Folders></m:RootFolder>'));

		$folders = $this->client()->listFolders();

		$this->assertCount(3, $folders);
		$this->assertSame(['id' => 'INBOX-ID', 'name' => 'Boîte de réception', 'parentId' => null, 'total' => 12, 'unread' => 3, 'role' => 'inbox'], $folders[0]);
		$this->assertSame('INBOX-ID', $folders[1]['parentId']);
		$this->assertNull($folders[1]['role']);
		$this->assertSame('sentitems', $folders[2]['role']);
		$this->assertStringContainsString('Traversal="Deep"', $this->requests[1]);
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
