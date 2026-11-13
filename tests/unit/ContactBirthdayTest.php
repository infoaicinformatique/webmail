<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsContacts;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\VCard;

class ContactBirthdayTest extends EwsTestCase {
	public function testFromExchange(): void {
		// midnight in Paris, midnight UTC, midnight in New York: the same day
		$this->assertSame('1980-05-12', EwsContacts::birthdayFromEws('1980-05-11T22:00:00Z'));
		$this->assertSame('1980-05-12', EwsContacts::birthdayFromEws('1980-05-12T00:00:00Z'));
		$this->assertSame('1980-05-12', EwsContacts::birthdayFromEws('1980-05-12T04:00:00Z'));
		$this->assertSame('1980-05-12', EwsContacts::birthdayFromEws('1980-05-12T12:00:00Z'));
		$this->assertSame('', EwsContacts::birthdayFromEws(''));
		$this->assertSame('1980-05-12T12:00:00Z', EwsContacts::birthdayToEws('1980-05-12'));
	}

	public function testValidDates(): void {
		$now = strtotime('2026-11-13T08:00:00Z');
		$this->assertSame('1980-05-12', EwsContacts::birthday('1980-05-12', $now));
		$this->assertSame('1604-05-12', EwsContacts::birthday('1604-05-12', $now), 'no year (Outlook)');
		foreach (['1980-02-30', '12/05/1980', '1850-01-01', '2027-01-01', ''] as $bad) {
			$this->assertNull(EwsContacts::birthday($bad, $now), $bad);
		}
		$this->expectException(MailException::class);
		EwsContacts::normalize(['displayName' => 'X', 'birthday' => '1980-13-01']);
	}

	public function testReadWrittenCleared(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:Contact><t:ItemId Id="C1"/><t:DisplayName>Alice</t:DisplayName><t:Birthday>1980-05-11T22:00:00Z</t:Birthday></t:Contact></m:Items>'));
		$this->queue(self::ok('CreateItem', '<m:Items><t:Contact><t:ItemId Id="C2"/></t:Contact></m:Items>'));
		$this->queue(self::ok('UpdateItem', '<m:Items><t:Contact><t:ItemId Id="C1"/></t:Contact></m:Items>'));
		$this->queue(self::ok('UpdateItem', '<m:Items><t:Contact><t:ItemId Id="C1"/></t:Contact></m:Items>'));
		$c = new EwsContacts($this->client());
		$this->assertSame('1980-05-12', $c->getContact('C1')['birthday']);
		$this->assertStringContainsString('<t:FieldURI FieldURI="contacts:Birthday"/>', $this->lastRequest());
		// schema order: after the phone numbers, before the department
		$c->createContact(['displayName' => 'Bob', 'mobilePhone' => '06 01 02 03 04', 'department' => 'Achats', 'birthday' => '1975-01-31']);
		$this->assertStringContainsString('</t:PhoneNumbers><t:Birthday>1975-01-31T12:00:00Z</t:Birthday><t:Department>Achats</t:Department>', $this->lastRequest());
		$c->updateContact('C1', ['displayName' => 'Alice', 'birthday' => '1980-05-12']);
		$this->assertStringContainsString('<t:SetItemField><t:FieldURI FieldURI="contacts:Birthday"/><t:Contact><t:Birthday>1980-05-12T12:00:00Z</t:Birthday></t:Contact></t:SetItemField>', $this->lastRequest());
		$c->updateContact('C1', ['displayName' => 'Alice', 'birthday' => '']);
		$this->assertStringContainsString('<t:DeleteItemField><t:FieldURI FieldURI="contacts:Birthday"/></t:DeleteItemField>', $this->lastRequest());
	}

	public function testVCard(): void {
		$vcf = VCard::export([['displayName' => 'Alice', 'emails' => [], 'birthday' => '1980-05-12'], ['displayName' => 'Bob', 'emails' => [], 'birthday' => '1604-01-31']]);
		$this->assertStringContainsString("BDAY;VALUE=date:19800512\r\n", $vcf);
		$this->assertStringContainsString("BDAY;VALUE=date:--0131\r\n", $vcf);
		$cards = VCard::parse("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:A\r\nBDAY:1980-05-12\r\nEND:VCARD\r\n"
			. "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:B\r\nBDAY:--0131\r\nEND:VCARD\r\n"
			. "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:C\r\nBDAY;VALUE=text:circa 1800\r\nEND:VCARD\r\n");
		$this->assertSame(['1980-05-12', '1604-01-31', ''], array_column($cards, 'birthday'));
	}
}
