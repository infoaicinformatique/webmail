<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Service\MeetingReminderService;

class MeetingReminderServiceTest extends EwsTestCase {
	private function ev(string $id, string $start, int $reminder, bool $cancelled = false): array {
		return ['id' => $id, 'start' => $start, 'end' => $start, 'reminder' => $reminder, 'isCancelled' => $cancelled];
	}

	public function testDueReminders(): void {
		$now = new \DateTimeImmutable('2026-11-12T13:50:00Z');
		$due = MeetingReminderService::due([
			$this->ev('in-10-min-15-before', '2026-11-12T14:00:00Z', 15),
			$this->ev('in-10-min-5-before', '2026-11-12T14:00:00Z', 5),
			$this->ev('tomorrow-1-day-before', '2026-11-13T13:00:00Z', 1440),
			$this->ev('no-reminder', '2026-11-12T13:55:00Z', -1),
			$this->ev('cancelled', '2026-11-12T13:55:00Z', 15, true),
			$this->ev('started-2-min-ago', '2026-11-12T13:48:00Z', 15),
			$this->ev('started-10-min-ago', '2026-11-12T13:40:00Z', 15),
			$this->ev('paris-time', '2026-11-12T14:55:00+01:00', 15),
		], $now);
		$this->assertSame(['in-10-min-15-before', 'tomorrow-1-day-before', 'started-2-min-ago', 'paris-time'], array_column($due, 'id'));
	}

	public function testOverEvents(): void {
		$now = new \DateTimeImmutable('2026-11-12T14:00:00Z');
		$this->assertSame(['a', 'c'], MeetingReminderService::over(['a' => '2026-11-12T13:59:00Z', 'b' => '2026-11-12T15:00:00Z', 'c' => ''], $now));
	}

	public function testOneNotificationPerOccurrence(): void {
		$this->assertNotSame(MeetingReminderService::objectId('E1', '2026-11-12T14:00:00Z'), MeetingReminderService::objectId('E1', '2026-11-19T14:00:00Z'));
		$this->assertSame(64, strlen(MeetingReminderService::objectId(str_repeat('AAMk', 60), '2026-11-12T14:00:00Z')));
	}

	public function testEventsWithTheirReminder(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="2" IncludesLastItemInRange="true"><t:Items>'
			. '<t:CalendarItem><t:ItemId Id="E1" ChangeKey="C"/><t:Subject>Comité</t:Subject><t:ReminderIsSet>true</t:ReminderIsSet><t:ReminderMinutesBeforeStart>15</t:ReminderMinutesBeforeStart>'
			. '<t:Start>2026-11-12T14:00:00Z</t:Start><t:End>2026-11-12T15:00:00Z</t:End><t:IsAllDayEvent>false</t:IsAllDayEvent><t:CalendarItemType>Single</t:CalendarItemType></t:CalendarItem>'
			. '<t:CalendarItem><t:ItemId Id="E2" ChangeKey="C"/><t:Subject>Sans rappel</t:Subject><t:ReminderIsSet>false</t:ReminderIsSet><t:ReminderMinutesBeforeStart>15</t:ReminderMinutesBeforeStart>'
			. '<t:Start>2026-11-12T16:00:00Z</t:Start><t:End>2026-11-12T17:00:00Z</t:End><t:IsAllDayEvent>false</t:IsAllDayEvent><t:CalendarItemType>Single</t:CalendarItemType></t:CalendarItem>'
			. '</t:Items></m:RootFolder>'));
		$events = (new EwsCalendar($this->client()))->withReminders(new \DateTimeImmutable('2026-11-12T13:45:00Z'), new \DateTimeImmutable('2026-11-13T14:45:00Z'));
		$this->assertSame([15, -1], array_column($events, 'reminder'));
		$this->assertSame(['Comité', 'Sans rappel'], array_column($events, 'subject'));
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:FieldURI FieldURI="item:ReminderIsSet"/><t:FieldURI FieldURI="item:ReminderMinutesBeforeStart"/>', $req);
		$this->assertStringContainsString('<m:CalendarView MaxEntriesReturned="200" StartDate="2026-11-12T13:45:00Z" EndDate="2026-11-13T14:45:00Z"/>', $req);
	}
}
