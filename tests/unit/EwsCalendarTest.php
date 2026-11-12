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

	public function testGetSeries(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:CalendarItem><t:ItemId Id="MASTER1" ChangeKey="MK"/><t:Subject>Sport</t:Subject>'
			. '<t:Body BodyType="Text"></t:Body><t:ReminderIsSet>false</t:ReminderIsSet>'
			. '<t:Start>2026-11-16T17:00:00Z</t:Start><t:End>2026-11-16T18:00:00Z</t:End><t:IsAllDayEvent>false</t:IsAllDayEvent>'
			. '<t:IsMeeting>false</t:IsMeeting><t:IsRecurring>true</t:IsRecurring><t:CalendarItemType>RecurringMaster</t:CalendarItemType><t:MyResponseType>Organizer</t:MyResponseType>'
			. '<t:Recurrence><t:WeeklyRecurrence><t:Interval>2</t:Interval><t:DaysOfWeek>Monday Thursday</t:DaysOfWeek><t:FirstDayOfWeek>Monday</t:FirstDayOfWeek></t:WeeklyRecurrence>'
			. '<t:EndDateRecurrence><t:StartDate>2026-11-16+01:00</t:StartDate><t:EndDate>2027-03-31+01:00</t:EndDate></t:EndDateRecurrence></t:Recurrence>'
			. '</t:CalendarItem></m:Items>'));
		$series = $this->calendar()->getSeries('OCC1');
		$this->assertStringContainsString('<m:ItemIds><t:RecurringMasterItemId OccurrenceId="OCC1"/></m:ItemIds>', $this->lastRequest());
		$this->assertStringContainsString('<t:FieldURI FieldURI="calendar:Recurrence"/>', $this->lastRequest());
		$this->assertSame('MASTER1', $series['id']);
		$this->assertSame('RecurringMaster', $series['type']);
		$this->assertSame(['freq' => 'weekly', 'pattern' => 'WeeklyRecurrence', 'interval' => 2, 'days' => ['Monday', 'Thursday'],
			'dayOfMonth' => null, 'by' => 'date', 'weekIndex' => null, 'until' => '2027-03-31', 'count' => null], $series['recurrence']);
	}

	public function testSecondTuesdayOfTheMonth(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:CalendarItem><t:ItemId Id="M2"/><t:Subject>Comité</t:Subject>'
			. '<t:Start>2026-11-10T09:00:00Z</t:Start><t:End>2026-11-10T10:00:00Z</t:End>'
			. '<t:Recurrence><t:RelativeMonthlyRecurrence><t:Interval>1</t:Interval><t:DaysOfWeek>Tuesday</t:DaysOfWeek><t:DayOfWeekIndex>Second</t:DayOfWeekIndex></t:RelativeMonthlyRecurrence>'
			. '<t:NumberedRecurrence><t:StartDate>2026-11-10</t:StartDate><t:NumberOfOccurrences>12</t:NumberOfOccurrences></t:NumberedRecurrence></t:Recurrence>'
			. '</t:CalendarItem></m:Items>'));
		$r = $this->calendar()->getSeries('OCC2')['recurrence'];
		$this->assertSame(['monthly', 'weekday', 'Second', ['Tuesday'], 12], [$r['freq'], $r['by'], $r['weekIndex'], $r['days'], $r['count']]);
	}

	public function testLastFridayOfNovember(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:CalendarItem><t:ItemId Id="M3"/><t:Subject>Inventaire</t:Subject>'
			. '<t:Start>2026-11-27T09:00:00Z</t:Start><t:End>2026-11-27T10:00:00Z</t:End>'
			. '<t:Recurrence><t:RelativeYearlyRecurrence><t:DaysOfWeek>Friday</t:DaysOfWeek><t:DayOfWeekIndex>Last</t:DayOfWeekIndex><t:Month>November</t:Month></t:RelativeYearlyRecurrence>'
			. '<t:NoEndRecurrence><t:StartDate>2026-11-27</t:StartDate></t:NoEndRecurrence></t:Recurrence>'
			. '</t:CalendarItem></m:Items>'));
		$r = $this->calendar()->getSeries('OCC3')['recurrence'];
		$this->assertSame(['yearly', 'last', 'Last'], [$r['freq'], $r['by'], $r['weekIndex']]);
	}

	public function testUnsupportedPatternIsReportedAsOther(): void {
		// "the 2nd weekday of the month": not one day of the week
		$this->queue(self::ok('GetItem', '<m:Items><t:CalendarItem><t:ItemId Id="M2"/><t:Subject>Comité</t:Subject>'
			. '<t:Start>2026-11-03T09:00:00Z</t:Start><t:End>2026-11-03T10:00:00Z</t:End>'
			. '<t:Recurrence><t:RelativeMonthlyRecurrence><t:Interval>1</t:Interval><t:DaysOfWeek>Weekday</t:DaysOfWeek><t:DayOfWeekIndex>Second</t:DayOfWeekIndex></t:RelativeMonthlyRecurrence>'
			. '<t:NumberedRecurrence><t:StartDate>2026-11-03</t:StartDate><t:NumberOfOccurrences>12</t:NumberOfOccurrences></t:NumberedRecurrence></t:Recurrence>'
			. '</t:CalendarItem></m:Items>'));
		$r = $this->calendar()->getSeries('OCC2')['recurrence'];
		$this->assertSame('other', $r['freq']);
		$this->assertSame('RelativeMonthlyRecurrence', $r['pattern']);
		$this->assertSame(12, $r['count']);
	}

	/**
	 * @dataProvider relativeRules
	 */
	public function testCreateOnAWeekday(string $start, array $rule, string $expected): void {
		$this->queue(self::ok('CreateItem', '<m:Items><t:CalendarItem><t:ItemId Id="N"/></t:CalendarItem></m:Items>'));
		$this->calendar()->createEvent(['subject' => 'x', 'start' => $start . 'T10:00', 'end' => $start . 'T11:00', 'recurrence' => $rule]);
		$this->assertStringContainsString($expected, $this->lastRequest());
	}

	public static function relativeRules(): array {
		return [
			'2nd Tuesday' => ['2026-11-10', ['freq' => 'monthly', 'by' => 'weekday', 'interval' => 2],
				'<t:RelativeMonthlyRecurrence><t:Interval>2</t:Interval><t:DaysOfWeek>Tuesday</t:DaysOfWeek><t:DayOfWeekIndex>Second</t:DayOfWeekIndex></t:RelativeMonthlyRecurrence>'],
			'5th Monday is the last' => ['2026-11-30', ['freq' => 'monthly', 'by' => 'weekday'],
				'<t:DaysOfWeek>Monday</t:DaysOfWeek><t:DayOfWeekIndex>Last</t:DayOfWeekIndex>'],
			'last Friday' => ['2026-11-27', ['freq' => 'monthly', 'by' => 'last'],
				'<t:DaysOfWeek>Friday</t:DaysOfWeek><t:DayOfWeekIndex>Last</t:DayOfWeekIndex>'],
			'4th Thursday of November' => ['2026-11-26', ['freq' => 'yearly', 'by' => 'weekday'],
				'<t:RelativeYearlyRecurrence><t:DaysOfWeek>Thursday</t:DaysOfWeek><t:DayOfWeekIndex>Fourth</t:DayOfWeekIndex><t:Month>November</t:Month></t:RelativeYearlyRecurrence>'],
		];
	}

	public function testLastNeedsTheLastWeek(): void {
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('pas le dernier');
		EwsCalendar::normalize(['start' => '2026-11-10T10:00', 'end' => '2026-11-10T11:00', 'recurrence' => ['freq' => 'monthly', 'by' => 'last']]);
	}

	public function testUpdateSeriesWithNewRule(): void {
		$this->queue(self::ok('UpdateItem', '<m:Items/>'));
		$this->calendar()->updateEvent('MASTER1', ['subject' => 'Sport collectif', 'start' => '2026-11-16T18:30', 'end' => '2026-11-16T19:30',
			'recurrence' => ['freq' => 'weekly', 'interval' => 2, 'days' => ['Thursday', 'Monday'], 'count' => 10]], true);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:ItemId Id="MASTER1"/>', $req);
		$this->assertStringContainsString('<t:SetItemField><t:FieldURI FieldURI="calendar:Recurrence"/><t:CalendarItem><t:Recurrence>'
			. '<t:WeeklyRecurrence><t:Interval>2</t:Interval><t:DaysOfWeek>Monday Thursday</t:DaysOfWeek></t:WeeklyRecurrence>'
			. '<t:NumberedRecurrence><t:StartDate>2026-11-16</t:StartDate><t:NumberOfOccurrences>10</t:NumberOfOccurrences></t:NumberedRecurrence>'
			. '</t:Recurrence></t:CalendarItem></t:SetItemField>', $req);
		$this->assertStringContainsString('<t:Start>2026-11-16T18:30:00</t:Start>', $req);
	}

	public function testUpdateSeriesKeepsOrRemovesRule(): void {
		$this->queue(self::ok('UpdateItem', '<m:Items/>'));
		$this->queue(self::ok('UpdateItem', '<m:Items/>'));
		$this->queue(self::ok('UpdateItem', '<m:Items/>'));
		$cal = $this->calendar();
		$cal->updateEvent('M', ['subject' => 'x', 'start' => '2026-11-16T10:00', 'end' => '2026-11-16T11:00', 'recurrence' => ['freq' => 'keep']], true);
		$this->assertStringNotContainsString('calendar:Recurrence', $this->lastRequest());
		$cal->updateEvent('M', ['subject' => 'x', 'start' => '2026-11-16T10:00', 'end' => '2026-11-16T11:00', 'recurrence' => ['freq' => 'none']], true);
		$this->assertStringContainsString('<t:DeleteItemField><t:FieldURI FieldURI="calendar:Recurrence"/></t:DeleteItemField>', $this->lastRequest());
		// a single occurrence never touches the rule
		$cal->updateEvent('OCC', ['subject' => 'x', 'start' => '2026-11-16T10:00', 'end' => '2026-11-16T11:00', 'recurrence' => ['freq' => 'daily']]);
		$this->assertStringNotContainsString('calendar:Recurrence', $this->lastRequest());
	}

	public function testCreateWithIntervalAndDailyUntil(): void {
		$this->queue(self::ok('CreateItem', '<m:Items><t:CalendarItem><t:ItemId Id="N"/></t:CalendarItem></m:Items>'));
		$this->calendar()->createEvent(['subject' => 'x', 'start' => '2026-11-30T10:00', 'end' => '2026-11-30T11:00',
			'recurrence' => ['freq' => 'monthly', 'interval' => '3', 'until' => '2027-12-31']]);
		$this->assertStringContainsString('<t:AbsoluteMonthlyRecurrence><t:Interval>3</t:Interval><t:DayOfMonth>30</t:DayOfMonth></t:AbsoluteMonthlyRecurrence>'
			. '<t:EndDateRecurrence><t:StartDate>2026-11-30</t:StartDate><t:EndDate>2027-12-31</t:EndDate></t:EndDateRecurrence>', $this->lastRequest());
	}

	/**
	 * @dataProvider badRecurrences
	 */
	public function testInvalidRecurrence(array $rec, string $message): void {
		$this->expectException(MailException::class);
		$this->expectExceptionMessage($message);
		EwsCalendar::normalize(['start' => '2026-11-17T14:00', 'end' => '2026-11-17T15:00', 'recurrence' => $rec]);
	}

	public static function badRecurrences(): array {
		return [
			[['freq' => 'hourly'], 'Répétition inconnue'],
			[['freq' => 'weekly', 'days' => ['Lundi']], 'Jour de la semaine inconnu'],
			[['freq' => 'daily', 'interval' => 0], 'intervalle'],
			[['freq' => 'daily', 'until' => '2026-11-01'], 'avant le début'],
			[['freq' => 'daily', 'count' => 5000], 'nombre d\'occurrences'],
		];
	}

	public function testMoveEventOnlyChangesStartAndEnd(): void {
		$this->queue(self::ok('UpdateItem', '<m:Items/>'));
		$this->calendar()->moveEvent('E1', '2026-11-19T10:15', '2026-11-19T11:45', false, false);
		$req = $this->lastRequest();
		$this->assertStringContainsString('SendMeetingInvitationsOrCancellations="SendToNone"', $req);
		$this->assertStringContainsString('<t:Updates><t:SetItemField><t:FieldURI FieldURI="calendar:Start"/><t:CalendarItem><t:Start>2026-11-19T10:15:00</t:Start></t:CalendarItem></t:SetItemField>'
			. '<t:SetItemField><t:FieldURI FieldURI="calendar:End"/><t:CalendarItem><t:End>2026-11-19T11:45:00</t:End></t:CalendarItem></t:SetItemField></t:Updates>', $req);
		$this->assertSame(2, substr_count($req, '<t:SetItemField>'));
	}

	public function testMoveAllDayMeetingNotifiesAttendees(): void {
		$this->queue(self::ok('UpdateItem', '<m:Items/>'));
		$this->calendar()->moveEvent('E2', '2026-11-23', '2026-11-24', true, true);
		$req = $this->lastRequest();
		$this->assertStringContainsString('SendMeetingInvitationsOrCancellations="SendToAllAndSaveCopy"', $req);
		$this->assertStringContainsString('<t:Start>2026-11-23T00:00:00</t:Start>', $req);
		$this->assertStringContainsString('<t:End>2026-11-25T00:00:00</t:End>', $req);
	}

	public function testMoveRejectsEndBeforeStart(): void {
		$this->expectException(MailException::class);
		$this->calendar()->moveEvent('E1', '2026-11-19T10:00', '2026-11-19T09:00', false, false);
	}

	public function testListOwnCalendars(): void {
		$this->queue('<m:GetFolderResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"><m:ResponseMessages>'
			. '<m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:CalendarFolder><t:FolderId Id="CAL-DEF"/></t:CalendarFolder></m:Folders></m:GetFolderResponseMessage>'
			. '</m:ResponseMessages></m:GetFolderResponse>');
		$this->queue(self::ok('FindFolder', '<m:RootFolder TotalItemsInView="4" IncludesLastItemInRange="true"><t:Folders>'
			. '<t:Folder><t:FolderId Id="INBOX"/><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Boîte de réception</t:DisplayName></t:Folder>'
			. '<t:CalendarFolder><t:FolderId Id="CAL-DEF"/><t:FolderClass>IPF.Appointment</t:FolderClass><t:DisplayName>Calendrier</t:DisplayName></t:CalendarFolder>'
			. '<t:CalendarFolder><t:FolderId Id="CAL-2"/><t:FolderClass>IPF.Appointment</t:FolderClass><t:DisplayName>Congés équipe</t:DisplayName></t:CalendarFolder>'
			. '<t:CalendarFolder><t:FolderId Id="CAL-3"/><t:FolderClass>IPF.Appointment.Birthday</t:FolderClass><t:DisplayName>Anniversaires</t:DisplayName></t:CalendarFolder>'
			. '<t:ContactsFolder><t:FolderId Id="CONTACTS"/><t:FolderClass>IPF.Contact</t:FolderClass><t:DisplayName>Contacts</t:DisplayName></t:ContactsFolder>'
			. '</t:Folders></m:RootFolder>'));
		$cals = $this->calendar()->listOwnCalendars();
		$this->assertSame(['calendar', 'CAL-3', 'CAL-2'], array_column($cals, 'key'));
		$this->assertSame(['Calendrier', 'Anniversaires', 'Congés équipe'], array_column($cals, 'name'));
		$this->assertTrue($cals[1]['canEdit']);
	}

	public function testSharedCalendarFolderUsesTheOtherMailbox(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="0" IncludesLastItemInRange="true"><t:Items/></m:RootFolder>'));
		$this->calendar()->findEvents(new \DateTimeImmutable('2026-11-01T00:00:00Z'), new \DateTimeImmutable('2026-12-01T00:00:00Z'), 'shared:marie@x.fr');
		$this->assertStringContainsString('<m:ParentFolderIds><t:DistinguishedFolderId Id="calendar"><t:Mailbox><t:EmailAddress>marie@x.fr</t:EmailAddress></t:Mailbox></t:DistinguishedFolderId></m:ParentFolderIds>', $this->lastRequest());
	}

	public function testCreateInSecondaryCalendar(): void {
		$this->queue(self::ok('CreateItem', '<m:Items><t:CalendarItem><t:ItemId Id="N"/></t:CalendarItem></m:Items>'));
		$this->calendar()->createEvent(['subject' => 'x', 'start' => '2026-11-20T10:00', 'end' => '2026-11-20T11:00'], 'CAL-2');
		$this->assertStringContainsString('<m:SavedItemFolderId><t:FolderId Id="CAL-2"/></m:SavedItemFolderId>', $this->lastRequest());
	}

	public function testInvalidSharedKey(): void {
		$this->expectException(MailException::class);
		$this->calendar()->folderXml('shared:pas-une-adresse');
	}

	public function testProbeSharedCalendarWithEditRights(): void {
		$this->queue(self::ok('GetFolder', '<m:Folders><t:CalendarFolder><t:FolderId Id="MARIE-CAL"/>'
			. '<t:EffectiveRights><t:CreateAssociated>false</t:CreateAssociated><t:CreateContents>true</t:CreateContents><t:CreateHierarchy>false</t:CreateHierarchy>'
			. '<t:Delete>true</t:Delete><t:Modify>true</t:Modify><t:Read>true</t:Read></t:EffectiveRights></t:CalendarFolder></m:Folders>'));
		$this->assertSame(['mode' => 'full', 'canEdit' => true], $this->calendar()->probeSharedCalendar('marie@x.fr'));
		$this->assertStringContainsString('<t:FieldURI FieldURI="folder:EffectiveRights"/>', $this->lastRequest());
	}

	public function testProbeFallsBackToFreeBusy(): void {
		$this->queue('<m:GetFolderResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages"><m:ResponseMessages>'
			. '<m:GetFolderResponseMessage ResponseClass="Error"><m:MessageText>Access is denied.</m:MessageText><m:ResponseCode>ErrorAccessDenied</m:ResponseCode></m:GetFolderResponseMessage>'
			. '</m:ResponseMessages></m:GetFolderResponse>');
		$this->queue(self::freeBusyResponse(''));
		$this->assertSame(['mode' => 'freebusy', 'canEdit' => false], $this->calendar()->probeSharedCalendar('paul@x.fr'));
		$this->assertStringContainsString('<m:GetUserAvailabilityRequest>', $this->lastRequest());
	}

	public function testProbeUnknownMailbox(): void {
		$this->queue('<m:GetFolderResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages"><m:ResponseMessages>'
			. '<m:GetFolderResponseMessage ResponseClass="Error"><m:MessageText>No mailbox.</m:MessageText><m:ResponseCode>ErrorNonExistentMailbox</m:ResponseCode></m:GetFolderResponseMessage>'
			. '</m:ResponseMessages></m:GetFolderResponse>');
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('Aucune boîte Exchange ne correspond à inconnu@x.fr');
		$this->calendar()->probeSharedCalendar('inconnu@x.fr');
	}

	private static function freeBusyResponse(string $events, string $class = 'Success', string $code = 'NoError'): string {
		return '<GetUserAvailabilityResponse xmlns="http://schemas.microsoft.com/exchange/services/2006/messages"><FreeBusyResponseArray><FreeBusyResponse>'
			. '<ResponseMessage ResponseClass="' . $class . '"><ResponseCode>' . $code . '</ResponseCode></ResponseMessage>'
			. '<FreeBusyView><FreeBusyViewType xmlns="http://schemas.microsoft.com/exchange/services/2006/types">Detailed</FreeBusyViewType>'
			. '<CalendarEventArray xmlns="http://schemas.microsoft.com/exchange/services/2006/types">' . $events . '</CalendarEventArray>'
			. '</FreeBusyView></FreeBusyResponse></FreeBusyResponseArray></GetUserAvailabilityResponse>';
	}

	public function testFreeBusy(): void {
		$this->queue(self::freeBusyResponse(
			'<CalendarEvent><StartTime>2026-11-16T08:00:00</StartTime><EndTime>2026-11-16T09:30:00</EndTime><BusyType>Busy</BusyType>'
			. '<CalendarEventDetails><ID>X</ID><Subject>Comité</Subject><Location>Salle A</Location><IsMeeting>true</IsMeeting><IsRecurring>false</IsRecurring>'
			. '<IsException>false</IsException><IsReminderSet>true</IsReminderSet><IsPrivate>false</IsPrivate></CalendarEventDetails></CalendarEvent>'
			. '<CalendarEvent><StartTime>2026-11-17T13:00:00</StartTime><EndTime>2026-11-17T14:00:00</EndTime><BusyType>Tentative</BusyType></CalendarEvent>'
			. '<CalendarEvent><StartTime>2026-11-18T13:00:00</StartTime><EndTime>2026-11-18T14:00:00</EndTime><BusyType>Free</BusyType></CalendarEvent>'));
		$events = $this->calendar()->freeBusy('paul@x.fr', new \DateTimeImmutable('2026-11-16T00:00:00Z'), new \DateTimeImmutable('2026-11-23T00:00:00Z'));
		$this->assertCount(2, $events);
		$this->assertSame('Comité', $events[0]['subject']);
		$this->assertSame('2026-11-16T08:00:00Z', $events[0]['start']);
		$this->assertSame('Salle A', $events[0]['location']);
		$this->assertTrue($events[0]['freeBusyOnly']);
		$this->assertSame('Provisoire', $events[1]['subject']);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:Address>paul@x.fr</t:Address>', $req);
		$this->assertStringContainsString('<t:StartTime>2026-11-16T00:00:00</t:StartTime><t:EndTime>2026-11-23T00:00:00</t:EndTime>', $req);
	}

	public function testFreeBusyNotShared(): void {
		$this->queue(self::freeBusyResponse('', 'Error', 'ErrorNoFreeBusyAccess'));
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('pas partagé');
		$this->calendar()->freeBusy('x@x.fr', new \DateTimeImmutable('2026-11-16'), new \DateTimeImmutable('2026-11-17'));
	}

	public function testFreeBusyRangeLimit(): void {
		$this->expectException(MailException::class);
		$this->calendar()->freeBusy('x@x.fr', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-06-01'));
	}

	private static function permissionResponse(): string {
		return self::ok('GetFolder', '<m:Folders><t:CalendarFolder><t:FolderId Id="CAL"/><t:PermissionSet><t:CalendarPermissions>'
			. '<t:CalendarPermission><t:UserId><t:DistinguishedUser>Default</t:DistinguishedUser></t:UserId><t:CanCreateItems>false</t:CanCreateItems>'
			. '<t:ReadItems>TimeOnly</t:ReadItems><t:CalendarPermissionLevel>FreeBusyTimeOnly</t:CalendarPermissionLevel></t:CalendarPermission>'
			. '<t:CalendarPermission><t:UserId><t:DistinguishedUser>Anonymous</t:DistinguishedUser></t:UserId><t:CalendarPermissionLevel>None</t:CalendarPermissionLevel></t:CalendarPermission>'
			. '<t:CalendarPermission><t:UserId><t:SID>S-1-5-21-1</t:SID><t:PrimarySmtpAddress>Paul@x.fr</t:PrimarySmtpAddress><t:DisplayName>Paul Durand</t:DisplayName></t:UserId>'
			. '<t:CanCreateItems>true</t:CanCreateItems><t:CanCreateSubFolders>false</t:CanCreateSubFolders><t:IsFolderOwner>false</t:IsFolderOwner>'
			. '<t:IsFolderVisible>true</t:IsFolderVisible><t:IsFolderContact>false</t:IsFolderContact><t:EditItems>Owned</t:EditItems><t:DeleteItems>Owned</t:DeleteItems>'
			. '<t:ReadItems>FullDetails</t:ReadItems><t:CalendarPermissionLevel>Custom</t:CalendarPermissionLevel></t:CalendarPermission>'
			. '<t:CalendarPermission><t:UserId><t:PrimarySmtpAddress>marie@x.fr</t:PrimarySmtpAddress><t:DisplayName>Marie &amp; Co</t:DisplayName></t:UserId>'
			. '<t:CalendarPermissionLevel>Reviewer</t:CalendarPermissionLevel></t:CalendarPermission>'
			. '</t:CalendarPermissions></t:PermissionSet></t:CalendarFolder></m:Folders>');
	}

	public function testGetPermissions(): void {
		$this->queue(self::permissionResponse());
		$perms = $this->calendar()->getPermissions('calendar');
		$this->assertSame([
			['user' => 'Default', 'name' => '', 'level' => 'FreeBusyTimeOnly'],
			['user' => 'Anonymous', 'name' => '', 'level' => 'None'],
			['user' => 'paul@x.fr', 'name' => 'Paul Durand', 'level' => 'Custom'],
			['user' => 'marie@x.fr', 'name' => 'Marie & Co', 'level' => 'Reviewer'],
		], $perms);
		$this->assertStringContainsString('<t:FieldURI FieldURI="folder:PermissionSet"/>', $this->lastRequest());
	}

	public function testSetPermissionsKeepsCustomEntriesAndDistinguishedUsers(): void {
		$this->queue(self::permissionResponse());
		$this->queue(self::ok('UpdateFolder', '<m:Folders><t:CalendarFolder><t:FolderId Id="CAL"/></t:CalendarFolder></m:Folders>'));
		$this->calendar()->setPermissions('CAL-2', [
			['user' => 'Default', 'level' => 'FreeBusyTimeAndSubjectAndLocation'],
			['user' => 'paul@x.fr', 'level' => 'keep'],
			['user' => 'Anne <ANNE@x.fr>', 'level' => 'Editor'],
			// marie removed
		], 'user@example.fr');
		$req = $this->lastRequest();
		$this->assertStringContainsString('<m:UpdateFolder><m:FolderChanges><t:FolderChange><t:FolderId Id="CAL-2"/>', $req);
		$this->assertStringContainsString('<t:CalendarPermission><t:UserId><t:DistinguishedUser>Default</t:DistinguishedUser></t:UserId><t:CalendarPermissionLevel>FreeBusyTimeAndSubjectAndLocation</t:CalendarPermissionLevel></t:CalendarPermission>', $req);
		// custom entry rebuilt with all its settings, without the SID
		$this->assertStringContainsString('<t:UserId><t:PrimarySmtpAddress>Paul@x.fr</t:PrimarySmtpAddress><t:DisplayName>Paul Durand</t:DisplayName></t:UserId><t:CanCreateItems>true</t:CanCreateItems>', $req);
		$this->assertStringContainsString('<t:EditItems>Owned</t:EditItems><t:DeleteItems>Owned</t:DeleteItems><t:ReadItems>FullDetails</t:ReadItems><t:CalendarPermissionLevel>Custom</t:CalendarPermissionLevel>', $req);
		$this->assertStringNotContainsString('S-1-5-21-1', $req);
		$this->assertStringContainsString('<t:PrimarySmtpAddress>anne@x.fr</t:PrimarySmtpAddress></t:UserId><t:CalendarPermissionLevel>Editor</t:CalendarPermissionLevel>', $req);
		$this->assertStringNotContainsString('marie@x.fr', $req);
		// Anonymous was not sent by the UI: kept from Exchange
		$this->assertStringContainsString('<t:DistinguishedUser>Anonymous</t:DistinguishedUser></t:UserId><t:CalendarPermissionLevel>None</t:CalendarPermissionLevel>', $req);
	}

	/**
	 * @dataProvider badPermissions
	 */
	public function testSetPermissionsValidation(array $entries, string $message): void {
		$this->queue(self::permissionResponse());
		$this->expectException(MailException::class);
		$this->expectExceptionMessage($message);
		$this->calendar()->setPermissions('calendar', $entries, 'user@example.fr');
	}

	public static function badPermissions(): array {
		return [
			[[['user' => 'pas-une-adresse', 'level' => 'Reviewer']], 'Adresse invalide'],
			[[['user' => 'user@example.fr', 'level' => 'Reviewer']], 'déjà propriétaire'],
			[[['user' => 'a@x.fr', 'level' => 'Reviewer'], ['user' => 'A@x.fr', 'level' => 'Editor']], 'en double'],
			[[['user' => 'a@x.fr', 'level' => 'Owner']], 'Niveau de partage inconnu'],
			[[['user' => 'nobody@x.fr', 'level' => 'keep']], 'introuvable'],
		];
	}

	public function testCannotShareSomeoneElsesCalendar(): void {
		$this->expectException(MailException::class);
		$this->calendar()->setPermissions('shared:marie@x.fr', [], 'user@example.fr');
	}

	public function testTimeZoneMapping(): void {
		$this->assertSame('Romance Standard Time', TimeZones::toWindows('Europe/Paris'));
		$this->assertSame('Mauritius Standard Time', TimeZones::toWindows('Indian/Reunion'));
		$this->assertSame('SA Western Standard Time', TimeZones::toWindows('America/Martinique'));
		$this->assertSame('UTC', TimeZones::toWindows('Mars/Olympus'));
		$this->assertSame('UTC', TimeZones::toWindows(null));
	}

	public function testEventSearchIgnoresAccentsAndCapitals(): void {
		$ev = ['subject' => 'Réunion Équipe', 'location' => 'Salle Riviera', 'organizer' => ['name' => 'Marie Martin', 'email' => 'marie@x.fr']];
		$this->assertTrue(EwsCalendar::matches($ev, 'reunion'));
		$this->assertTrue(EwsCalendar::matches($ev, 'EQUIPE riviera'), 'all the words, anywhere');
		$this->assertTrue(EwsCalendar::matches($ev, 'marie'), 'the organiser too');
		$this->assertFalse(EwsCalendar::matches($ev, 'réunion budget'));
		$this->assertFalse(EwsCalendar::matches($ev, '   '));
	}

	public function testSearchEventsOverAYear(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="2" IncludesLastItemInRange="true"><t:Items>'
			. '<t:CalendarItem><t:ItemId Id="E2"/><t:Subject>Revue budget</t:Subject><t:Start>2026-12-01T09:00:00Z</t:Start><t:End>2026-12-01T10:00:00Z</t:End></t:CalendarItem>'
			. '<t:CalendarItem><t:ItemId Id="E1"/><t:Subject>Budget prévisionnel</t:Subject><t:Start>2026-06-01T09:00:00Z</t:Start><t:End>2026-06-01T10:00:00Z</t:End></t:CalendarItem>'
			. '<t:CalendarItem><t:ItemId Id="E3"/><t:Subject>Sport</t:Subject><t:Start>2026-11-20T09:00:00Z</t:Start><t:End>2026-11-20T10:00:00Z</t:End></t:CalendarItem>'
			. '</t:Items></m:RootFolder>'));
		$r = $this->calendar()->searchEvents('budget', 'calendar', new \DateTimeImmutable('2026-11-12T10:00:00Z'));
		$this->assertSame(['E1', 'E2'], array_column($r, 'id'), 'matching ones, soonest first');
		$this->assertStringContainsString('StartDate="2026-05-13T10:00:00Z" EndDate="2027-05-14T10:00:00Z"', $this->lastRequest());
	}

	public function testSearchNeedsTwoLetters(): void {
		$this->expectException(MailException::class);
		$this->calendar()->searchEvents('a');
	}
}
