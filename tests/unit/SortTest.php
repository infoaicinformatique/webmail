<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsClient;

class SortTest extends EwsTestCase {
	public function testSortOrders(): void {
		$this->assertSame('<m:SortOrder><t:FieldOrder Order="Descending"><t:FieldURI FieldURI="item:DateTimeReceived"/></t:FieldOrder></m:SortOrder>',
			EwsClient::sortOrderXml());
		$this->assertSame('<m:SortOrder><t:FieldOrder Order="Ascending"><t:FieldURI FieldURI="item:DateTimeReceived"/></t:FieldOrder></m:SortOrder>',
			EwsClient::sortOrderXml('date', false));
		// then by date, newest first
		$this->assertSame('<m:SortOrder><t:FieldOrder Order="Ascending"><t:ExtendedFieldURI PropertyTag="0x0042" PropertyType="String"/></t:FieldOrder>'
			. '<t:FieldOrder Order="Descending"><t:FieldURI FieldURI="item:DateTimeReceived"/></t:FieldOrder></m:SortOrder>',
			EwsClient::sortOrderXml('from', false));
		$this->assertStringStartsWith('<m:SortOrder><t:FieldOrder Order="Descending"><t:FieldURI FieldURI="item:Size"/>', EwsClient::sortOrderXml('size'));
		// unknown: by date
		$this->assertSame(EwsClient::sortOrderXml(), EwsClient::sortOrderXml('<x/>'));
	}

	public function testListSortedWithSize(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="1" IncludesLastItemInRange="true"><t:Items>'
			. '<t:Message><t:ItemId Id="M1" ChangeKey="CK"/><t:Subject>Gros fichier</t:Subject><t:Size>2048000</t:Size></t:Message>'
			. '</t:Items></m:RootFolder>'));
		$list = $this->client()->findItems('inbox', 0, 50, null, 'subject', false);
		$this->assertSame(2048000, $list['items'][0]['size']);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:FieldURI FieldURI="item:Size"/>', $req);
		$this->assertStringContainsString('<m:SortOrder><t:FieldOrder Order="Ascending"><t:FieldURI FieldURI="item:Subject"/></t:FieldOrder>', $req);
	}
}
