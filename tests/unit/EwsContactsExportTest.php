<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsContacts;

class EwsContactsExportTest extends EwsTestCase {
	public function testExportReadsEveryContactInBatches(): void {
		$ids = '';
		for ($i = 1; $i <= 3; $i++) {
			$ids .= '<t:Contact><t:ItemId Id="C' . $i . '"/></t:Contact>';
		}
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="4" IncludesLastItemInRange="true"><t:Items>' . $ids
			. '<t:DistributionList><t:ItemId Id="G1"/></t:DistributionList></t:Items></m:RootFolder>'));
		$this->queue(self::ok('GetItem', '<m:Items>'
			. '<t:Contact><t:ItemId Id="C1"/><t:Body BodyType="Text">Note</t:Body><t:DisplayName>Jean Dupont</t:DisplayName><t:GivenName>Jean</t:GivenName>'
			. '<t:CompanyName>Client</t:CompanyName><t:EmailAddresses><t:Entry Key="EmailAddress1">jean@x.fr</t:Entry></t:EmailAddresses>'
			. '<t:PhoneNumbers><t:Entry Key="MobilePhone">06</t:Entry></t:PhoneNumbers><t:Surname>Dupont</t:Surname></t:Contact>'
			. '<t:Contact><t:ItemId Id="C2"/><t:DisplayName>Marie</t:DisplayName></t:Contact>'
			. '<t:Contact><t:ItemId Id="C3"/><t:DisplayName>Paul</t:DisplayName></t:Contact>'
			. '</m:Items>'));
		$contacts = (new EwsContacts($this->client()))->exportContacts('contacts');
		$this->assertSame(['Jean Dupont', 'Marie', 'Paul'], array_column($contacts, 'displayName'));
		$this->assertSame(['jean@x.fr'], $contacts[0]['emails']);
		$this->assertSame('06', $contacts[0]['mobilePhone']);
		$this->assertStringContainsString('<m:ItemIds><t:ItemId Id="C1"/><t:ItemId Id="C2"/><t:ItemId Id="C3"/></m:ItemIds>', $this->lastRequest());
		$this->assertStringNotContainsString('G1', $this->lastRequest());
	}

	public function testKnownEmails(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="2" IncludesLastItemInRange="true"><t:Items>'
			. '<t:Contact><t:ItemId Id="C1"/><t:EmailAddresses><t:Entry Key="EmailAddress1">Jean@X.fr</t:Entry><t:Entry Key="EmailAddress2">SMTP:jd@perso.fr</t:Entry></t:EmailAddresses></t:Contact>'
			. '<t:Contact><t:ItemId Id="C2"/></t:Contact></t:Items></m:RootFolder>'));
		$this->assertSame(['jean@x.fr' => true, 'jd@perso.fr' => true], (new EwsContacts($this->client()))->knownEmails());
	}
}
