<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Ews\TimeZones;
use OCA\Webmail\Exception\MailException;

class EwsCalendarTest extends EwsTestCase {
	private function calendar(): EwsCalendar {
		$client = $this->client();
		$client->setTimeZone('Romance Standard Time');
		return new EwsCalendar($client);
	}

	public function testFindEvents(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="2" IncludesLastItemInRange="true"><t:Items>'
			. '<t:CalendarItem><t:ItemId Id="E1" ChangeKey="K1"/><t:Subject>Réunion équipe</t:Subject>'
			. '<t:Start>2026-11-16T08:00:00Z</t:Start><t:End>2026-11-16T09:00:00Z</t:End><t:IsAllDayEvent>false</t:IsAllDayEvent>'
			. '<t:LegacyFreeBusyStatus>Busy</t:LegacyFreeBusyStatus><t:Location>Salle A</t:Location><t:IsMeeting>true</t:IsMeeting>'
			. '<t:IsCancelled>false</t:IsCancelled><t:IsRecurring>true</t:IsRecurring><t:CalendarItemType>Occurrence</t:CalendarItemType>'
			. '<t:MyResponseType>Accept</t:MyResponseType><t:Organizer><t:Mailbox><t:Name>Marie</t:Name><t:EmailAddress>marie@x.fr</t:EmailAddress></t:Mailbox></t:Organizer></t:CalendarItem>'
			. '<t:CalendarItem><t:ItemId Id="E2" ChangeKey="K2"/><t:Subject>Congés</t:Subject>'
			. '<t:Start>2026-11-19T23:00:00Z</t:Start><t:End>2026-11-21T23:00:00Z</t:End><t:IsAllDayEvent>true</t:IsAllDayEvent>'
			. '<t:IsMeeting>false</t:IsMeeting><t:MyResponseType>Organizer</t:MyResponseType><t:CalendarItemType>Single</t:CalendarItemType></t:CalendarItem>'
			. '</t:Items></m:RootFolder>'));

		$events = $this->calendar()->findEvents(new \DateTimeImmutable('2026-11-01T00:00:00+01:00'), new \DateTimeImmutable('2026-12-01T00:00:00+01:00'));

		$this->assertCount(2, $events);
		$this->assertSame('Réunion équipe', $events[0]['subject']);
		$this->assertSame('2026-11-16T08:00:00Z', $events[0]['start']);
		$this->assertTrue($events[0]['isMeeting']);
		$this->assertFalse($events[0]['isOrganizer']);
		$this->assertSame('Accept', $events[0]['response']);
		$this->assertSame('Occurrence', $events[0]['type']);
		$this->assertSame('marie@x.fr', $events[0]['organizer']['email']);
		$this->assertTrue($events[1]['allDay']);
		$this->assertTrue($events[1]['isOrganizer']);

		$req = $this->lastRequest();
		$this->assertStringContainsString('<m:CalendarView MaxEntriesReturned="1000" StartDate="2026-10-31T23:00:00Z" EndDate="2026-11-30T23:00:00Z"/>', $req);
		$this->assertStringContainsString('<t:TimeZoneContext><t:TimeZoneDefinition Id="Romance Standard Time"/></t:TimeZoneContext>', $req);
	}

	public function testRangeTooLong(): void {
		$this->expectException(MailException::class);
		$this->calendar()->findEvents(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2028-01-01'));
	}

	public function testGetEventWithAttendees(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:CalendarItem><t:ItemId Id="E1" ChangeKey="K1"/><t:Subject>Point projet</t:Subject>'
			. '<t:Body BodyType="Text">Ordre du jour</t:Body><t:ReminderIsSet>true</t:ReminderIsSet><t:ReminderMinutesBeforeStart>30</t:ReminderMinutesBeforeStart>'
			. '<t:Start>2026-11-16T08:00:00Z</t:Start><t:End>2026-11-16T09:00:00Z</t:End><t:IsAllDayEvent>false</t:IsAllDayEvent>'
			. '<t:IsMeeting>true</t:IsMeeting><t:MyResponseType>Organizer</t:MyResponseType>'
			. '<t:RequiredAttendees><t:Attendee><t:Mailbox><t:Name>Paul</t:Name><t:EmailAddress>paul@x.fr</t:EmailAddress></t:Mailbox><t:ResponseType>Tentative</t:ResponseType></t:Attendee></t:RequiredAttendees>'
			. '</t:CalendarItem></m:Items>'));
		$e = $this->calendar()->getEvent('E1');
		$this->assertSame('Ordre du jour', $e['body']);
		$this->assertSame(30, $e['reminder']);
		$this->assertTrue($e['isOrganizer']);
		$this->assertSame([['name' => 'Paul', 'email' => 'paul@x.fr', 'response' => 'Tentative']], $e['attendees']);
		$this->assertSame([], $e['optionalAttendees']);
		$this->assertStringContainsString('<t:BodyType>Text</t:BodyType>', $this->lastRequest());
	}

	public function testCreateMeetingWithRecurrence(): void {
		$this->queue(self::ok('CreateItem', '<m:Items><t:CalendarItem><t:ItemId Id="NEW1" ChangeKey="CK"/></t:CalendarItem></m:Items>'));
		$id = $this->calendar()->createEvent([
			'subject' => 'Comité <hebdo>',
			'start' => '2026-11-17T14:00',
			'end' => '2026-11-17T15:30',
			'location' => 'Visio',
			'body' => 'Lien & infos',
			'attendees' => 'Paul <paul@x.fr>, anne@x.fr',
			'reminder' => '10',
			'freeBusy' => 'Busy',
			'recurrence' => ['freq' => 'weekly', 'until' => '2027-03-31'],
		]);
		$this->assertSame('NEW1', $id);
		$req = $this->lastRequest();
		$this->assertStringContainsString('SendMeetingInvitations="SendToAllAndSaveCopy"', $req);
		$this->assertStringContainsString('<t:Subject>Comité &lt;hebdo&gt;</t:Subject>', $req);
		$this->assertStringContainsString('<t:Start>2026-11-17T14:00:00</t:Start><t:End>2026-11-17T15:30:00</t:End>', $req);
		$this->assertStringContainsString('<t:ReminderMinutesBeforeStart>10</t:ReminderMinutesBeforeStart>', $req);
		$this->assertStringContainsString('<t:EmailAddress>paul@x.fr</t:EmailAddress>', $req);
		$this->assertStringContainsString('<t:WeeklyRecurrence><t:Interval>1</t:Interval><t:DaysOfWeek>Tuesday</t:DaysOfWeek></t:WeeklyRecurrence>', $req);
		$this->assertStringContainsString('<t:EndDateRecurrence><t:StartDate>2026-11-17</t:StartDate><t:EndDate>2027-03-31</t:EndDate></t:EndDateRecurrence>', $req);
		$this->assertStringContainsString('<t:StartTimeZone Id="Romance Standard Time"/>', $req);
	}

	public function testCreateAllDayEventWithoutAttendees(): void {
		$this->queue(self::ok('CreateItem', '<m:Items><t:CalendarItem><t:ItemId Id="NEW2"/></t:CalendarItem></m:Items>'));
		$this->calendar()->createEvent(['subject' => 'Salon', 'allDay' => true, 'start' => '2026-11-20', 'end' => '2026-11-21', 'reminder' => -1,
			'recurrence' => ['freq' => 'yearly']]);
		$req = $this->lastRequest();
		$this->assertStringContainsString('SendMeetingInvitations="SendToNone"', $req);
		$this->assertStringContainsString('<t:Start>2026-11-20T00:00:00</t:Start><t:End>2026-11-22T00:00:00</t:End><t:IsAllDayEvent>true</t:IsAllDayEvent>', $req);
		$this->assertStringContainsString('<t:ReminderIsSet>false</t:ReminderIsSet><t:Start>', $req);
		$this->assertStringContainsString('<t:AbsoluteYearlyRecurrence><t:DayOfMonth>20</t:DayOfMonth><t:Month>November</t:Month>', $req);
		$this->assertStringContainsString('<t:NoEndRecurrence>', $req);
	}

	/**
	 * @dataProvider recurrences
	 */
	public function testOtherRecurrencesAreSchemaValid(string $freq, string $expected): void {
		$this->queue(self::ok('CreateItem', '<m:Items><t:CalendarItem><t:ItemId Id="N"/></t:CalendarItem></m:Items>'));
		$this->calendar()->createEvent(['subject' => 'x', 'start' => '2026-11-30T10:00', 'end' => '2026-11-30T11:00', 'recurrence' => ['freq' => $freq]]);
		$this->assertStringContainsString($expected, $this->lastRequest());
	}

	public static function recurrences(): array {
		return [
			['daily', '<t:DailyRecurrence><t:Interval>1</t:Interval></t:DailyRecurrence>'],
			['monthly', '<t:AbsoluteMonthlyRecurrence><t:Interval>1</t:Interval><t:DayOfMonth>30</t:DayOfMonth></t:AbsoluteMonthlyRecurrence>'],
		];
	}

	public function testInvalidDates(): void {
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('après son début');
		EwsCalendar::normalize(['start' => '2026-11-17T15:00', 'end' => '2026-11-17T14:00']);
	}

	public function testInvalidAttendee(): void {
		$this->expectException(MailException::class);
		EwsCalendar::normalize(['start' => '2026-11-17T14:00', 'end' => '2026-11-17T15:00', 'attendees' => ['pas-une-adresse']]);
	}

	public function testUpdateEvent(): void {
		$this->queue(self::ok('UpdateItem', '<m:Items/>'));
		$this->calendar()->updateEvent('E1', ['subject' => 'Déplacé', 'start' => '2026-11-18T10:00', 'end' => '2026-11-18T11:00', 'reminder' => 15]);
		$req = $this->lastRequest();
		$this->assertStringContainsString('SendMeetingInvitationsOrCancellations="SendToNone"', $req);
		$this->assertStringContainsString('<t:SetItemField><t:FieldURI FieldURI="calendar:Start"/><t:CalendarItem><t:Start>2026-11-18T10:00:00</t:Start></t:CalendarItem></t:SetItemField>', $req);
		$this->assertStringContainsString('<t:DeleteItemField><t:FieldURI FieldURI="calendar:Location"/></t:DeleteItemField>', $req);
		$this->assertStringContainsString('<t:DeleteItemField><t:FieldURI FieldURI="calendar:RequiredAttendees"/></t:DeleteItemField>', $req);
	}

	public function testUpdateMeetingNotifiesAttendees(): void {
		$this->queue(self::ok('UpdateItem', '<m:Items/>'));
		$this->calendar()->updateEvent('E1', ['subject' => 'x', 'start' => '2026-11-18T10:00', 'end' => '2026-11-18T11:00', 'location' => 'Salle B',
			'attendees' => ['paul@x.fr'], 'optionalAttendees' => ['anne@x.fr']]);
		$req = $this->lastRequest();
		$this->assertStringContainsString('SendMeetingInvitationsOrCancellations="SendToAllAndSaveCopy"', $req);
		$this->assertStringContainsString('<t:OptionalAttendees><t:Attendee><t:Mailbox><t:EmailAddress>anne@x.fr</t:EmailAddress>', $req);
	}

	public function testDeleteOccurrenceAndSeries(): void {
		$this->queue(self::ok('DeleteItem'));
		$this->queue(self::ok('DeleteItem'));
		$cal = $this->calendar();
		$cal->deleteEvent('OCC1');
		$this->assertStringContainsString('SendMeetingCancellations="SendToAllAndSaveCopy"', $this->lastRequest());
		$this->assertStringContainsString('<t:ItemId Id="OCC1"/>', $this->lastRequest());
		$cal->deleteEvent('OCC1', true);
		$this->assertStringContainsString('<t:RecurringMasterItemId OccurrenceId="OCC1"/>', $this->lastRequest());
	}

	/**
	 * @dataProvider responses
	 */
	public function testRespond(string $response, string $element): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:MeetingRequest><t:ItemId Id="MR1" ChangeKey="FRESH"/></t:MeetingRequest></m:Items>'));
		$this->queue(self::ok('CreateItem', '<m:Items/>'));
		$this->calendar()->respond('MR1', $response, 'Je serai en retard');
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:' . $element . '><t:Body BodyType="Text">Je serai en retard</t:Body><t:ReferenceItemId Id="MR1" ChangeKey="FRESH"/>', $req);
		$this->assertStringContainsString('MessageDisposition="SendAndSaveCopy"', $req);
	}

	public static function responses(): array {
		return [['accept', 'AcceptItem'], ['tentative', 'TentativelyAcceptItem'], ['decline', 'DeclineItem']];
	}

	public function testTimeZoneMapping(): void {
		$this->assertSame('Romance Standard Time', TimeZones::toWindows('Europe/Paris'));
		$this->assertSame('Mauritius Standard Time', TimeZones::toWindows('Indian/Reunion'));
		$this->assertSame('SA Western Standard Time', TimeZones::toWindows('America/Martinique'));
		$this->assertSame('UTC', TimeZones::toWindows('Mars/Olympus'));
		$this->assertSame('UTC', TimeZones::toWindows(null));
	}
}
