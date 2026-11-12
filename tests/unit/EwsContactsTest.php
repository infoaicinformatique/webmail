<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsContacts;
use OCA\Webmail\Exception\MailException;

class EwsContactsTest extends EwsTestCase {
	private function contacts(): EwsContacts {
		return new EwsContacts($this->client());
	}

	public function testListContacts(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="250" IncludesLastItemInRange="false"><t:Items>'
			. '<t:Contact><t:ItemId Id="C1"/><t:DisplayName>Jean Dupont</t:DisplayName><t:CompanyName>Client SA</t:CompanyName>'
			. '<t:EmailAddresses><t:Entry Key="EmailAddress1">jean@client.fr</t:Entry></t:EmailAddresses>'
			. '<t:PhoneNumbers><t:Entry Key="BusinessPhone">01 02 03 04 05</t:Entry></t:PhoneNumbers><t:JobTitle>DAF</t:JobTitle></t:Contact>'
			. '<t:DistributionList><t:ItemId Id="G1"/><t:DisplayName>Équipe</t:DisplayName></t:DistributionList>'
			. '</t:Items></m:RootFolder>'));
		$res = $this->contacts()->listContacts(0, 100, 'dupont');
		$this->assertSame(250, $res['total']);
		$this->assertTrue($res['hasMore']);
		$this->assertSame(['id' => 'C1', 'displayName' => 'Jean Dupont', 'company' => 'Client SA', 'jobTitle' => 'DAF',
			'email' => 'jean@client.fr', 'phone' => '01 02 03 04 05', 'isGroup' => false], $res['items'][0]);
		$this->assertTrue($res['items'][1]['isGroup']);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:IndexedFieldURI FieldURI="contacts:EmailAddress" FieldIndex="EmailAddress1"/>', $req);
		$this->assertStringContainsString('<t:DistinguishedFolderId Id="contacts"/>', $req);
		$this->assertStringContainsString('<m:QueryString>dupont</m:QueryString>', $req);
	}

	public function testGetContact(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:Contact><t:ItemId Id="C1"/><t:Body BodyType="Text">Client depuis 2019</t:Body>'
			. '<t:DisplayName>Jean Dupont</t:DisplayName><t:GivenName>Jean</t:GivenName><t:CompanyName>Client SA</t:CompanyName>'
			. '<t:EmailAddresses><t:Entry Key="EmailAddress1">jean@client.fr</t:Entry><t:Entry Key="EmailAddress2">j.dupont@perso.fr</t:Entry></t:EmailAddresses>'
			. '<t:PhysicalAddresses><t:Entry Key="Business"><t:Street>1 rue de la Paix</t:Street><t:City>Nice</t:City><t:PostalCode>06000</t:PostalCode></t:Entry></t:PhysicalAddresses>'
			. '<t:PhoneNumbers><t:Entry Key="MobilePhone">06 00 00 00 00</t:Entry></t:PhoneNumbers><t:Surname>Dupont</t:Surname></t:Contact></m:Items>'));
		$c = $this->contacts()->getContact('C1');
		$this->assertSame('Jean', $c['givenName']);
		$this->assertSame('Dupont', $c['surname']);
		$this->assertSame(['jean@client.fr', 'j.dupont@perso.fr'], $c['emails']);
		$this->assertSame('06 00 00 00 00', $c['mobilePhone']);
		$this->assertSame('', $c['businessPhone']);
		$this->assertSame('Nice', $c['city']);
		$this->assertSame('06000', $c['postalCode']);
		$this->assertSame('Client depuis 2019', $c['notes']);
	}

	public function testCreateContact(): void {
		$this->queue(self::ok('CreateItem', '<m:Items><t:Contact><t:ItemId Id="NEWC"/></t:Contact></m:Items>'));
		$id = $this->contacts()->createContact([
			'givenName' => 'Anne', 'surname' => 'Martin', 'company' => 'ACME & Co', 'jobTitle' => 'Gérante', 'department' => 'Direction',
			'emails' => ['anne@acme.fr', '', 'anne.perso@x.fr'], 'mobilePhone' => '06 11', 'businessPhone' => '04 22',
			'street' => '2 av. Jean Médecin', 'city' => 'Nice', 'postalCode' => '06000', 'country' => 'France', 'notes' => 'VIP',
		]);
		$this->assertSame('NEWC', $id);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:DisplayName>Anne Martin</t:DisplayName>', $req);
		$this->assertStringContainsString('<t:CompanyName>ACME &amp; Co</t:CompanyName>', $req);
		$this->assertStringContainsString('<t:Entry Key="EmailAddress2">anne.perso@x.fr</t:Entry>', $req);
		$this->assertStringContainsString('<t:Entry Key="Business"><t:Street>2 av. Jean Médecin</t:Street><t:City>Nice</t:City><t:CountryOrRegion>France</t:CountryOrRegion><t:PostalCode>06000</t:PostalCode></t:Entry>', $req);
	}

	public function testUpdateContact(): void {
		$this->queue(self::ok('UpdateItem', '<m:Items/>'));
		$this->contacts()->updateContact('C1', ['displayName' => 'Jean D.', 'emails' => ['jean@client.fr'], 'city' => 'Antibes']);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:SetItemField><t:IndexedFieldURI FieldURI="contacts:EmailAddress" FieldIndex="EmailAddress1"/><t:Contact><t:EmailAddresses><t:Entry Key="EmailAddress1">jean@client.fr</t:Entry>', $req);
		$this->assertStringContainsString('<t:DeleteItemField><t:IndexedFieldURI FieldURI="contacts:EmailAddress" FieldIndex="EmailAddress2"/></t:DeleteItemField>', $req);
		$this->assertStringContainsString('<t:IndexedFieldURI FieldURI="contacts:PhysicalAddress:City" FieldIndex="Business"/><t:Contact><t:PhysicalAddresses><t:Entry Key="Business"><t:City>Antibes</t:City>', $req);
		$this->assertStringContainsString('<t:DeleteItemField><t:IndexedFieldURI FieldURI="contacts:PhoneNumber" FieldIndex="MobilePhone"/></t:DeleteItemField>', $req);
		$this->assertStringContainsString('<t:FileAs>Jean D.</t:FileAs>', $req);
	}

	public function testDeleteContact(): void {
		$this->queue(self::ok('DeleteItem'));
		$this->contacts()->deleteContact('C1');
		$this->assertStringContainsString('DeleteType="MoveToDeletedItems"', $this->lastRequest());
	}

	public function testContactNeedsAName(): void {
		$this->expectException(MailException::class);
		EwsContacts::normalize(['jobTitle' => 'x']);
	}

	public function testDisplayNameFallsBackToEmail(): void {
		$this->assertSame('a@b.fr', EwsContacts::normalize(['emails' => ['a@b.fr']])['displayName']);
	}

	public function testResolveNames(): void {
		$this->queue('<m:ResolveNamesResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"><m:ResponseMessages>'
			. '<m:ResolveNamesResponseMessage ResponseClass="Warning"><m:MessageText>Multiple results were found.</m:MessageText><m:ResponseCode>ErrorNameResolutionMultipleResults</m:ResponseCode>'
			. '<m:ResolutionSet TotalItemsInView="3" IncludesLastItemInRange="true">'
			. '<t:Resolution><t:Mailbox><t:Name>Marie Martin</t:Name><t:EmailAddress>marie@societe-a.fr</t:EmailAddress><t:RoutingType>SMTP</t:RoutingType><t:MailboxType>Mailbox</t:MailboxType></t:Mailbox></t:Resolution>'
			. '<t:Resolution><t:Mailbox><t:Name>Martin Client</t:Name><t:EmailAddress>martin@client.fr</t:EmailAddress><t:RoutingType>SMTP</t:RoutingType><t:MailboxType>Contact</t:MailboxType></t:Mailbox></t:Resolution>'
			. '<t:Resolution><t:Mailbox><t:Name>Interne</t:Name><t:EmailAddress>/O=EXCH/OU=X</t:EmailAddress><t:RoutingType>EX</t:RoutingType></t:Mailbox></t:Resolution>'
			. '</m:ResolutionSet></m:ResolveNamesResponseMessage></m:ResponseMessages></m:ResolveNamesResponse>');
		$res = $this->contacts()->resolve('mart');
		$this->assertSame([
			['name' => 'Marie Martin', 'email' => 'marie@societe-a.fr', 'source' => 'directory'],
			['name' => 'Martin Client', 'email' => 'martin@client.fr', 'source' => 'contacts'],
		], $res);
	}

	public function testResolveNoResultIsNotAnError(): void {
		$this->queue('<m:ResolveNamesResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages"><m:ResponseMessages>'
			. '<m:ResolveNamesResponseMessage ResponseClass="Error"><m:MessageText>No results were found.</m:MessageText><m:ResponseCode>ErrorNameResolutionNoResults</m:ResponseCode></m:ResolveNamesResponseMessage>'
			. '</m:ResponseMessages></m:ResolveNamesResponse>');
		$this->assertSame([], $this->contacts()->resolve('zzz'));
	}

	public function testListOwnBooksKeepsOnlyPlainContactFolders(): void {
		$this->queue('<m:GetFolderResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"><m:ResponseMessages>'
			. '<m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:ContactsFolder><t:FolderId Id="CT-DEF"/></t:ContactsFolder></m:Folders></m:GetFolderResponseMessage>'
			. '</m:ResponseMessages></m:GetFolderResponse>');
		$this->queue(self::ok('FindFolder', '<m:RootFolder TotalItemsInView="5" IncludesLastItemInRange="true"><t:Folders>'
			. '<t:ContactsFolder><t:FolderId Id="CT-DEF"/><t:FolderClass>IPF.Contact</t:FolderClass><t:DisplayName>Contacts</t:DisplayName></t:ContactsFolder>'
			. '<t:ContactsFolder><t:FolderId Id="CT-FOUR"/><t:FolderClass>IPF.Contact</t:FolderClass><t:DisplayName>Fournisseurs</t:DisplayName></t:ContactsFolder>'
			. '<t:ContactsFolder><t:FolderId Id="CT-RC"/><t:FolderClass>IPF.Contact.RecipientCache</t:FolderClass><t:DisplayName>Recipient Cache</t:DisplayName></t:ContactsFolder>'
			. '<t:ContactsFolder><t:FolderId Id="CT-GAL"/><t:FolderClass>IPF.Contact.GalContacts</t:FolderClass><t:DisplayName>GAL Contacts</t:DisplayName></t:ContactsFolder>'
			. '<t:Folder><t:FolderId Id="INBOX"/><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Inbox</t:DisplayName></t:Folder>'
			. '</t:Folders></m:RootFolder>'));
		$books = $this->contacts()->listOwnBooks();
		$this->assertSame(['contacts', 'CT-FOUR'], array_column($books, 'key'));
		$this->assertSame(['Contacts', 'Fournisseurs'], array_column($books, 'name'));
	}

	public function testSharedBookUsesTheOtherMailbox(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="0" IncludesLastItemInRange="true"><t:Items/></m:RootFolder>'));
		$this->contacts()->listContacts(0, 100, null, 'shared:marie@x.fr');
		$this->assertStringContainsString('<m:ParentFolderIds><t:DistinguishedFolderId Id="contacts"><t:Mailbox><t:EmailAddress>marie@x.fr</t:EmailAddress></t:Mailbox></t:DistinguishedFolderId></m:ParentFolderIds>', $this->lastRequest());
	}

	public function testCreateInAnotherBook(): void {
		$this->queue(self::ok('CreateItem', '<m:Items><t:Contact><t:ItemId Id="N"/></t:Contact></m:Items>'));
		$this->contacts()->createContact(['displayName' => 'Plombier'], 'CT-FOUR');
		$this->assertStringContainsString('<m:SavedItemFolderId><t:FolderId Id="CT-FOUR"/></m:SavedItemFolderId>', $this->lastRequest());
	}

	private const NS = 'xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"';

	private static function rights(bool $edit, bool $read = true): string {
		$e = $edit ? 'true' : 'false';
		return '<t:EffectiveRights><t:CreateAssociated>false</t:CreateAssociated><t:CreateContents>' . $e . '</t:CreateContents>'
			. '<t:CreateHierarchy>false</t:CreateHierarchy><t:Delete>' . $e . '</t:Delete><t:Modify>' . $e . '</t:Modify><t:Read>' . ($read ? 'true' : 'false') . '</t:Read></t:EffectiveRights>';
	}

	private static function folder(string $id, string $name, string $class, string $rights): string {
		return '<t:ContactsFolder><t:FolderId Id="' . $id . '"/><t:FolderClass>' . $class . '</t:FolderClass><t:DisplayName>' . $name . '</t:DisplayName>' . $rights . '</t:ContactsFolder>';
	}

	private static function error(string $op, string $code): string {
		return '<m:' . $op . 'ResponseMessage ResponseClass="Error"><m:MessageText>x</m:MessageText><m:ResponseCode>' . $code . '</m:ResponseCode></m:' . $op . 'ResponseMessage>';
	}

	private static function found(string $folders): string {
		return '<m:FindFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode>'
			. '<m:RootFolder TotalItemsInView="1" IncludesLastItemInRange="true"><t:Folders>' . $folders . '</t:Folders></m:RootFolder></m:FindFolderResponseMessage>';
	}

	public function testListSharedBooksFindsTheOtherAddressBooks(): void {
		$this->queue(self::ok('GetFolder', '<m:Folders>' . self::folder('MAIN', 'Contacts', 'IPF.Contact', self::rights(false)) . '</m:Folders>'));
		$this->queue('<m:FindFolderResponse ' . self::NS . '><m:ResponseMessages>'
			// top of the mailbox
			. self::found(self::folder('MAIN', 'Contacts', 'IPF.Contact', self::rights(false))
				. self::folder('VIP', 'Clients VIP', 'IPF.Contact', self::rights(true))
				. '<t:Folder><t:FolderId Id="INBOX"/><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Inbox</t:DisplayName>' . self::rights(false) . '</t:Folder>'
				. self::folder('HIDDEN', 'Privé', 'IPF.Contact', self::rights(false, false)))
			// inside « Contacts »
			. self::found(self::folder('ARTISANS', 'Artisans', 'IPF.Contact', self::rights(false))
				. self::folder('RC', 'Recipient Cache', 'IPF.Contact.RecipientCache', self::rights(false))
				. self::folder('VIP', 'Clients VIP', 'IPF.Contact', self::rights(true)))
			. '</m:ResponseMessages></m:FindFolderResponse>');
		$books = $this->contacts()->listSharedBooks('marie@x.fr');
		$this->assertSame([
			['folder' => '', 'name' => 'Contacts', 'canEdit' => false],
			['folder' => 'ARTISANS', 'name' => 'Artisans', 'canEdit' => false],
			['folder' => 'VIP', 'name' => 'Clients VIP', 'canEdit' => true],
		], $books);
		$this->assertStringContainsString('<m:FindFolder Traversal="Shallow">', $this->lastRequest());
		$this->assertStringContainsString('<m:ParentFolderIds><t:DistinguishedFolderId Id="msgfolderroot"><t:Mailbox><t:EmailAddress>marie@x.fr</t:EmailAddress></t:Mailbox></t:DistinguishedFolderId>'
			. '<t:DistinguishedFolderId Id="contacts"><t:Mailbox><t:EmailAddress>marie@x.fr</t:EmailAddress></t:Mailbox></t:DistinguishedFolderId></m:ParentFolderIds>', $this->lastRequest());
	}

	public function testListSharedBooksWithoutTheDefaultOne(): void {
		// « Contacts » not shared, the mailbox top visible with one shared book
		$this->queue('<m:GetFolderResponse ' . self::NS . '><m:ResponseMessages>' . self::error('GetFolder', 'ErrorAccessDenied') . '</m:ResponseMessages></m:GetFolderResponse>');
		$this->queue('<m:FindFolderResponse ' . self::NS . '><m:ResponseMessages>'
			. self::found(self::folder('PROS', 'Prospects', 'IPF.Contact', self::rights(false)))
			. self::error('FindFolder', 'ErrorAccessDenied')
			. '</m:ResponseMessages></m:FindFolderResponse>');
		$this->assertSame([['folder' => 'PROS', 'name' => 'Prospects', 'canEdit' => false]], $this->contacts()->listSharedBooks('paul@x.fr'));
	}

	public function testListSharedBooksNothingShared(): void {
		$this->queue('<m:GetFolderResponse ' . self::NS . '><m:ResponseMessages>' . self::error('GetFolder', 'ErrorAccessDenied') . '</m:ResponseMessages></m:GetFolderResponse>');
		$this->queue('<m:FindFolderResponse ' . self::NS . '><m:ResponseMessages>' . self::error('FindFolder', 'ErrorAccessDenied')
			. self::error('FindFolder', 'ErrorAccessDenied') . '</m:ResponseMessages></m:FindFolderResponse>');
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('ne partage pas de carnet d\'adresses avec vous');
		$this->contacts()->listSharedBooks('paul@x.fr');
	}

	public function testListSharedBooksUnknownMailbox(): void {
		$this->queue('<m:GetFolderResponse ' . self::NS . '><m:ResponseMessages>' . self::error('GetFolder', 'ErrorNonExistentMailbox') . '</m:ResponseMessages></m:GetFolderResponse>');
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('Aucune boîte Exchange ne correspond à inconnu@x.fr');
		$this->contacts()->listSharedBooks('inconnu@x.fr');
	}

	public function testColleagueSecondaryBookUsesItsFolderId(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="0" IncludesLastItemInRange="true"><t:Items/></m:RootFolder>'));
		$this->contacts()->listContacts(0, 100, null, 'shared:marie@x.fr:AAMk+/x=');
		$this->assertStringContainsString('<m:ParentFolderIds><t:FolderId Id="AAMk+/x="/></m:ParentFolderIds>', $this->lastRequest());
		$this->assertSame(['marie@x.fr', 'AAMk+/x='], EwsContacts::parseSharedKey('shared:Marie@x.fr:AAMk+/x='));
		$this->assertSame(['marie@x.fr', ''], EwsContacts::parseSharedKey('shared:marie@x.fr'));
		$this->assertNull(EwsContacts::parseSharedKey('CT-FOUR'));
		$this->assertSame('shared:marie@x.fr:F1', EwsContacts::sharedKey('Marie@x.fr', 'F1'));
	}

	public function testColleagueSecondaryBookCannotBeShared(): void {
		$this->expectException(MailException::class);
		$this->contacts()->getPermissions('shared:marie@x.fr:F1');
	}

	// ------------------------------------------------------------------ public folders

	public function testDiscoverPublicFolderMailbox(): void {
		$this->responses[] = [200, '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
			. '<GetUserSettingsResponseMessage xmlns="http://schemas.microsoft.com/exchange/2010/Autodiscover"><Response><ErrorCode>NoError</ErrorCode>'
			. '<UserResponses><UserResponse><ErrorCode>NoError</ErrorCode><UserSettings><UserSetting xmlns:i="http://www.w3.org/2001/XMLSchema-instance" i:type="StringSetting">'
			. '<Name>PublicFolderInformation</Name><Value>pf-mailbox@example.fr</Value></UserSetting></UserSettings></UserResponse></UserResponses>'
			. '</Response></GetUserSettingsResponseMessage></s:Body></s:Envelope>'];
		$this->assertSame('pf-mailbox@example.fr', $this->client()->discoverPublicFolderMailbox());
		$this->assertStringContainsString('<a:Mailbox>user@example.fr</a:Mailbox>', $this->autodiscover[0]);
		$this->assertStringContainsString('<a:Setting>PublicFolderInformation</a:Setting>', $this->autodiscover[0]);
		$this->assertStringContainsString('<wsa:To>https://ex3.mail.ovh.net/autodiscover/autodiscover.svc</wsa:To>', $this->autodiscover[0]);
	}

	public function testDiscoverPublicFolderMailboxIsOptional(): void {
		$this->responses[] = [404, ''];
		$this->assertNull($this->client()->discoverPublicFolderMailbox());
	}

	public function testPublicFolderRequestsAreRouted(): void {
		$client = $this->client();
		$client->usePublicFolderMailbox('pf-mailbox@example.fr');
		$this->queue(self::ok('FindFolder', '<m:RootFolder TotalItemsInView="0" IncludesLastItemInRange="true"><t:Folders/></m:RootFolder>'));
		(new EwsContacts($client))->listPublicFolders();
		$this->assertSame(['X-AnchorMailbox: pf-mailbox@example.fr', 'X-PublicFolderMailbox: pf-mailbox@example.fr'], $this->headers[0]);
		$this->assertStringContainsString('<m:FindFolder Traversal="Shallow">', $this->lastRequest());
		$this->assertStringContainsString('<m:ParentFolderIds><t:DistinguishedFolderId Id="publicfoldersroot"/></m:ParentFolderIds>', $this->lastRequest());
		// a normal client sends no routing header
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="0" IncludesLastItemInRange="true"><t:Items/></m:RootFolder>'));
		$this->contacts()->listContacts();
		$this->assertSame([], $this->headers[1]);
	}

	public function testListPublicFoldersKeepsContactsAndFoldersToOpen(): void {
		$f = fn (string $id, string $name, string $class, int $children, bool $read = true, bool $edit = false) => '<t:Folder><t:FolderId Id="' . $id . '"/>'
			. '<t:FolderClass>' . $class . '</t:FolderClass><t:DisplayName>' . $name . '</t:DisplayName><t:ChildFolderCount>' . $children . '</t:ChildFolderCount>'
			. self::rights($edit, $read) . '</t:Folder>';
		$this->queue(self::ok('FindFolder', '<m:RootFolder TotalItemsInView="5" IncludesLastItemInRange="true"><t:Folders>'
			. $f('SOC', 'Société', 'IPF.Note', 2)
			. $f('ANN', 'Annonces', 'IPF.Note', 0)
			. $f('AG', 'Agenda commun', 'IPF.Appointment', 0)
			. $f('CLI', 'Clients', 'IPF.Contact', 0, true, true)
			. $f('SECRET', 'Direction', 'IPF.Contact', 0, false)
			. '</t:Folders></m:RootFolder>'));
		$this->assertSame([
			['id' => 'CLI', 'name' => 'Clients', 'isContacts' => true, 'hasChildren' => false, 'canEdit' => true],
			['id' => 'SOC', 'name' => 'Société', 'isContacts' => false, 'hasChildren' => true, 'canEdit' => false],
		], $this->contacts()->listPublicFolders());
	}

	public function testListPublicFoldersOfAFolder(): void {
		$this->queue(self::ok('FindFolder', '<m:RootFolder TotalItemsInView="0" IncludesLastItemInRange="true"><t:Folders/></m:RootFolder>'));
		$this->contacts()->listPublicFolders('SOC');
		$this->assertStringContainsString('<m:ParentFolderIds><t:FolderId Id="SOC"/></m:ParentFolderIds>', $this->lastRequest());
	}

	public function testNoPublicFolders(): void {
		$this->queue('<m:FindFolderResponse ' . self::NS . '><m:ResponseMessages>' . self::error('FindFolder', 'ErrorFolderNotFound') . '</m:ResponseMessages></m:FindFolderResponse>');
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('Aucun dossier public n\'est accessible sur ce serveur Exchange');
		$this->contacts()->listPublicFolders();
	}

	public function testGetPublicBook(): void {
		$this->queue(self::ok('GetFolder', '<m:Folders>' . self::folder('CLI', 'Clients', 'IPF.Contact', self::rights(true)) . '</m:Folders>'));
		$this->assertSame(['name' => 'Clients', 'canEdit' => true], $this->contacts()->getPublicBook('CLI'));
		$this->assertStringContainsString('<m:FolderIds><t:FolderId Id="CLI"/></m:FolderIds>', $this->lastRequest());
	}

	public function testGetPublicBookRejectsOtherFolders(): void {
		$this->queue(self::ok('GetFolder', '<m:Folders><t:CalendarFolder><t:FolderId Id="AG"/><t:FolderClass>IPF.Appointment</t:FolderClass>'
			. '<t:DisplayName>Agenda</t:DisplayName>' . self::rights(true) . '</t:CalendarFolder></m:Folders>'));
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('n\'est pas un carnet d\'adresses');
		$this->contacts()->getPublicBook('AG');
	}

	public function testSearchInAPublicBookUsesARestriction(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="0" IncludesLastItemInRange="true"><t:Items/></m:RootFolder>'));
		$this->contacts()->listContacts(0, 100, 'azur', 'public:CLI');
		$req = $this->lastRequest();
		$this->assertStringNotContainsString('QueryString', $req);
		$this->assertStringContainsString('<t:Contains ContainmentMode="Substring" ContainmentComparison="IgnoreCase"><t:FieldURI FieldURI="contacts:DisplayName"/><t:Constant Value="azur"/></t:Contains>', $req);
		$this->assertStringContainsString('<m:ParentFolderIds><t:FolderId Id="CLI"/></m:ParentFolderIds>', $req);
	}

	public function testDeleteInAPublicBookIsPermanent(): void {
		$this->queue(self::ok('DeleteItem'));
		$this->contacts()->deleteContact('C1', true);
		$this->assertStringContainsString('<m:DeleteItem DeleteType="HardDelete">', $this->lastRequest());
	}

	public function testPublicBooksCannotBeShared(): void {
		$this->expectException(MailException::class);
		$this->contacts()->getPermissions('public:CLI');
	}

	public function testSearchDirectoryReturnsFullCards(): void {
		$this->queue('<m:ResolveNamesResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"><m:ResponseMessages>'
			. '<m:ResolveNamesResponseMessage ResponseClass="Warning"><m:ResponseCode>ErrorNameResolutionMultipleResults</m:ResponseCode><m:ResolutionSet TotalItemsInView="2" IncludesLastItemInRange="true">'
			. '<t:Resolution><t:Mailbox><t:Name>Paul Durand</t:Name><t:EmailAddress>paul@x.fr</t:EmailAddress><t:RoutingType>SMTP</t:RoutingType><t:MailboxType>Mailbox</t:MailboxType></t:Mailbox>'
			. '<t:Contact><t:DisplayName>Paul Durand</t:DisplayName><t:GivenName>Paul</t:GivenName><t:CompanyName>Société A</t:CompanyName>'
			. '<t:EmailAddresses><t:Entry Key="EmailAddress1">SMTP:paul@x.fr</t:Entry><t:Entry Key="EmailAddress2">/O=EXCH/CN=paul</t:Entry></t:EmailAddresses>'
			. '<t:PhoneNumbers><t:Entry Key="BusinessPhone">04 93 00 00 01</t:Entry><t:Entry Key="MobilePhone">06 00 00 00 01</t:Entry></t:PhoneNumbers>'
			. '<t:Department>Comptabilité</t:Department><t:JobTitle>Comptable</t:JobTitle><t:OfficeLocation>Nice</t:OfficeLocation><t:Surname>Durand</t:Surname></t:Contact></t:Resolution>'
			. '<t:Resolution><t:Mailbox><t:Name>Équipe Ventes</t:Name><t:EmailAddress>ventes@x.fr</t:EmailAddress><t:RoutingType>SMTP</t:RoutingType><t:MailboxType>PublicDL</t:MailboxType></t:Mailbox></t:Resolution>'
			. '</m:ResolutionSet></m:ResolveNamesResponseMessage></m:ResponseMessages></m:ResolveNamesResponse>');
		$cards = $this->contacts()->searchDirectory('pa');
		$this->assertStringContainsString('ReturnFullContactData="true" SearchScope="ActiveDirectory"', $this->lastRequest());
		$this->assertCount(2, $cards);
		$paul = $cards[0];
		$this->assertSame('directory:paul@x.fr', $paul['id']);
		$this->assertSame(['paul@x.fr'], $paul['emails'], 'X.500 addresses and the smtp: prefix are dropped');
		$this->assertSame('Comptable', $paul['jobTitle']);
		$this->assertSame('Comptabilité', $paul['department']);
		$this->assertSame('Nice', $paul['office']);
		$this->assertSame('06 00 00 00 01', $paul['phone']);
		$this->assertTrue($cards[1]['isGroup']);
	}

	public function testSearchDirectoryNeedsTwoCharacters(): void {
		$this->assertSame([], $this->contacts()->searchDirectory('a'));
		$this->assertSame([], $this->requests);
	}

	public function testGetAndSetBookPermissions(): void {
		$perms = self::ok('GetFolder', '<m:Folders><t:ContactsFolder><t:FolderId Id="CT"/><t:PermissionSet><t:Permissions>'
			. '<t:Permission><t:UserId><t:DistinguishedUser>Default</t:DistinguishedUser></t:UserId><t:PermissionLevel>None</t:PermissionLevel></t:Permission>'
			. '<t:Permission><t:UserId><t:DistinguishedUser>Anonymous</t:DistinguishedUser></t:UserId><t:PermissionLevel>None</t:PermissionLevel></t:Permission>'
			. '<t:Permission><t:UserId><t:SID>S-1</t:SID><t:PrimarySmtpAddress>paul@x.fr</t:PrimarySmtpAddress><t:DisplayName>Paul</t:DisplayName></t:UserId>'
			. '<t:CanCreateItems>true</t:CanCreateItems><t:ReadItems>FullDetails</t:ReadItems><t:PermissionLevel>Custom</t:PermissionLevel></t:Permission>'
			. '</t:Permissions></t:PermissionSet></t:ContactsFolder></m:Folders>');
		$this->queue($perms);
		$this->assertSame([
			['user' => 'Default', 'name' => '', 'level' => 'None'],
			['user' => 'Anonymous', 'name' => '', 'level' => 'None'],
			['user' => 'paul@x.fr', 'name' => 'Paul', 'level' => 'Custom'],
		], $this->contacts()->getPermissions('contacts'));

		$this->queue($perms);
		$this->queue(self::ok('UpdateFolder', '<m:Folders><t:ContactsFolder><t:FolderId Id="CT"/></t:ContactsFolder></m:Folders>'));
		$this->contacts()->setPermissions('CT-FOUR', [
			['user' => 'Default', 'level' => 'Reviewer'],
			['user' => 'paul@x.fr', 'level' => 'keep'],
			['user' => 'anne@x.fr', 'level' => 'Editor'],
		], 'user@example.fr');
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:FolderChange><t:FolderId Id="CT-FOUR"/><t:Updates><t:SetFolderField><t:FieldURI FieldURI="folder:PermissionSet"/><t:ContactsFolder><t:PermissionSet><t:Permissions>', $req);
		$this->assertStringContainsString('<t:Permission><t:UserId><t:DistinguishedUser>Default</t:DistinguishedUser></t:UserId><t:PermissionLevel>Reviewer</t:PermissionLevel></t:Permission>', $req);
		$this->assertStringContainsString('<t:CanCreateItems>true</t:CanCreateItems><t:ReadItems>FullDetails</t:ReadItems><t:PermissionLevel>Custom</t:PermissionLevel>', $req);
		$this->assertStringContainsString('<t:PrimarySmtpAddress>anne@x.fr</t:PrimarySmtpAddress></t:UserId><t:PermissionLevel>Editor</t:PermissionLevel>', $req);
		$this->assertStringContainsString('<t:DistinguishedUser>Anonymous</t:DistinguishedUser></t:UserId><t:PermissionLevel>None</t:PermissionLevel>', $req);
		$this->assertStringNotContainsString('Calendar', $req);
	}

	public function testFreeBusyLevelsDoNotExistForContacts(): void {
		$this->queue(self::ok('GetFolder', '<m:Folders><t:ContactsFolder><t:FolderId Id="CT"/></t:ContactsFolder></m:Folders>'));
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('Niveau de partage inconnu');
		$this->contacts()->setPermissions('contacts', [['user' => 'Default', 'level' => 'FreeBusyTimeOnly']], 'user@example.fr');
	}

	/**
	 * @dataProvider notMine
	 */
	public function testOnlyOwnBooksCanBeShared(string $book): void {
		$this->expectException(MailException::class);
		$this->contacts()->getPermissions($book);
	}

	public static function notMine(): array {
		return [['shared:marie@x.fr'], ['directory']];
	}

	public function testResolveIgnoresShortQueries(): void {
		$this->assertSame([], $this->contacts()->resolve('a'));
		$this->assertSame([], $this->requests);
	}
}
