<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Exception\MailException;

class RoomsTest extends EwsTestCase {
	private const NS = 'xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"';

	private static function address(string $name, string $email, string $type = 'PublicDL'): string {
		return '<t:Name>' . $name . '</t:Name><t:EmailAddress>' . $email . '</t:EmailAddress><t:RoutingType>SMTP</t:RoutingType><t:MailboxType>' . $type . '</t:MailboxType>';
	}

	public function testRoomLists(): void {
		$this->queue('<m:GetRoomListsResponse ResponseClass="Success" ' . self::NS . '><m:ResponseCode>NoError</m:ResponseCode><m:RoomLists>'
			. '<t:Address>' . self::address('Salles Paris', 'salles-paris@x.fr') . '</t:Address>'
			. '<t:Address>' . self::address('Salles Lyon', 'salles-lyon@x.fr') . '</t:Address>'
			. '</m:RoomLists></m:GetRoomListsResponse>');
		$lists = (new EwsCalendar($this->client()))->roomLists();
		$this->assertSame([['name' => 'Salles Lyon', 'email' => 'salles-lyon@x.fr'], ['name' => 'Salles Paris', 'email' => 'salles-paris@x.fr']], $lists);
		$this->assertStringContainsString('<m:GetRoomLists', $this->lastRequest());
	}

	public function testRoomsOfAList(): void {
		$this->queue('<m:GetRoomsResponse ResponseClass="Success" ' . self::NS . '><m:ResponseCode>NoError</m:ResponseCode><m:Rooms>'
			. '<t:Room><t:Id>' . self::address('Salle Mistral (8)', 'mistral@x.fr', 'Mailbox') . '</t:Id></t:Room>'
			. '<t:Room><t:Id>' . self::address('Salle Alizé (4)', 'alize@x.fr', 'Mailbox') . '</t:Id></t:Room>'
			. '</m:Rooms></m:GetRoomsResponse>');
		$rooms = (new EwsCalendar($this->client()))->rooms('salles-paris@x.fr');
		$this->assertSame(['Salle Alizé (4)', 'Salle Mistral (8)'], array_column($rooms, 'name'));
		$this->assertStringContainsString('<m:RoomList><t:EmailAddress>salles-paris@x.fr</t:EmailAddress></m:RoomList>', $this->lastRequest());
	}

	public function testUnknownRoomList(): void {
		$this->queue('<m:GetRoomsResponse ResponseClass="Error" ' . self::NS . '><m:MessageText>No results</m:MessageText>'
			. '<m:ResponseCode>ErrorNameResolutionNoResults</m:ResponseCode><m:DescriptiveLinkKey>0</m:DescriptiveLinkKey></m:GetRoomsResponse>');
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('Liste de salles introuvable');
		(new EwsCalendar($this->client()))->rooms('inconnue@x.fr');
	}

	public function testABadListIsNotSent(): void {
		$this->expectException(MailException::class);
		(new EwsCalendar($this->client()))->rooms('pas une adresse');
	}

	public function testRoomBookedAsResource(): void {
		$this->queue(self::ok('CreateItem', '<m:Items><t:CalendarItem><t:ItemId Id="E9" ChangeKey="C"/></t:CalendarItem></m:Items>'));
		(new EwsCalendar($this->client()))->createEvent(['subject' => 'Comité', 'start' => '2026-11-30T10:00', 'end' => '2026-11-30T11:00',
			'location' => 'Salle Mistral (8)', 'resources' => ['mistral@x.fr']]);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:Resources><t:Attendee><t:Mailbox><t:EmailAddress>mistral@x.fr</t:EmailAddress></t:Mailbox></t:Attendee></t:Resources>', $req);
		// the room gets a meeting request, so it can accept
		$this->assertStringContainsString('SendMeetingInvitations="SendToAllAndSaveCopy"', $req);
	}

	public function testRoomsKeptOrChangedOnUpdate(): void {
		$cal = new EwsCalendar($this->client());
		$base = ['subject' => 'x', 'start' => '2026-11-18T10:00', 'end' => '2026-11-18T11:00'];
		// a drag and drop (no resources): the room is left as it is
		$cal->updateEvent('E1', $base);
		$this->assertStringNotContainsString('calendar:Resources', $this->lastRequest());
		// the form without a room: removed
		$cal->updateEvent('E1', $base + ['resources' => []]);
		$this->assertStringContainsString('<t:DeleteItemField><t:FieldURI FieldURI="calendar:Resources"/></t:DeleteItemField>', $this->lastRequest());
		// another room
		$cal->updateEvent('E1', $base + ['resources' => ['alize@x.fr']]);
		$this->assertStringContainsString('<t:FieldURI FieldURI="calendar:Resources"/><t:CalendarItem><t:Resources><t:Attendee><t:Mailbox><t:EmailAddress>alize@x.fr', $this->lastRequest());
		$this->assertStringContainsString('SendMeetingInvitationsOrCancellations="SendToAllAndSaveCopy"', $this->lastRequest());
	}

	public function testRoomsOfAnEvent(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:CalendarItem><t:ItemId Id="E1" ChangeKey="C"/><t:Subject>Comité</t:Subject>'
			. '<t:Start>2026-11-30T09:00:00Z</t:Start><t:End>2026-11-30T10:00:00Z</t:End>'
			. '<t:Resources><t:Attendee><t:Mailbox><t:Name>Salle Mistral (8)</t:Name><t:EmailAddress>mistral@x.fr</t:EmailAddress></t:Mailbox>'
			. '<t:ResponseType>Accept</t:ResponseType></t:Attendee></t:Resources></t:CalendarItem></m:Items>'));
		$ev = (new EwsCalendar($this->client()))->getEvent('E1');
		$this->assertSame('mistral@x.fr', $ev['resources'][0]['email']);
		$this->assertSame('Accept', $ev['resources'][0]['response']);
		$this->assertStringContainsString('calendar:Resources', $this->lastRequest());
	}
}
