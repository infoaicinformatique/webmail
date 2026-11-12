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

	public function testResolveIgnoresShortQueries(): void {
		$this->assertSame([], $this->contacts()->resolve('a'));
		$this->assertSame([], $this->requests);
	}
}
