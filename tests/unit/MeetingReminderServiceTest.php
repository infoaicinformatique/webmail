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

	public function testTheLinkToJoinAMeeting(): void {
		$chat = 'https://chat.pbcannes.org';
		$room = 'https://chat.pbcannes.org/#/room/%21Zk3xQ9dHf2Lp8wVb5nRt7?via=matrix.pbcannes.org';
		// the room of the chat in the description, a place without a link
		$this->assertSame($room, MeetingReminderService::joinLink('Salle B', "Ordre du jour\n\nRejoindre la visio : " . $room . "\n", $chat));
		// Nextcloud Talk first
		$this->assertSame('https://nc.example.org/call/abc123', MeetingReminderService::joinLink('https://nc.example.org/call/abc123', $room, $chat));
		// Teams in the description, an address in the place
		$teams = 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_x/0';
		$this->assertSame($teams, MeetingReminderService::joinLink('12 rue de la Paix', 'Lien : ' . $teams . '.', ''));
		// a link given as the place
		$this->assertSame('https://visio.example.org/r/42', MeetingReminderService::joinLink('https://visio.example.org/r/42', '', ''));
		// a link said to be the visio, of any service
		$this->assertSame('https://visio.example.org/r/42', MeetingReminderService::joinLink('Bureau 2', "Ordre du jour.\n\nRejoindre la visio : https://visio.example.org/r/42\n", ''));
		$this->assertSame('https://meet.example.com/abc', MeetingReminderService::joinLink('', 'Join the meeting: https://meet.example.com/abc', ''));
		// nothing to join: a link of the description is not taken for any page
		$this->assertNull(MeetingReminderService::joinLink('Salle B', 'Le devis : https://example.org/devis.pdf', $chat));
		$this->assertNull(MeetingReminderService::joinLink('', '', ''));
	}

	public function testMeetingsSoonOrNow(): void {
		$now = new \DateTimeImmutable('2026-11-15T14:00:00Z');
		$events = [
			['id' => 'soon', 'start' => '2026-11-15T14:08:00Z', 'end' => '2026-11-15T15:00:00Z'],
			['id' => 'now', 'start' => '2026-11-15T13:30:00Z', 'end' => '2026-11-15T14:30:00Z'],
			['id' => 'later', 'start' => '2026-11-15T14:40:00Z', 'end' => '2026-11-15T15:00:00Z'],
			['id' => 'over', 'start' => '2026-11-15T13:00:00Z', 'end' => '2026-11-15T14:00:00Z'],
			['id' => 'allday', 'start' => '2026-11-15T00:00:00Z', 'end' => '2026-11-16T00:00:00Z', 'allDay' => true],
			['id' => 'cancelled', 'start' => '2026-11-15T14:05:00Z', 'end' => '2026-11-15T15:00:00Z', 'isCancelled' => true],
		];
		$this->assertSame(['soon', 'now'], array_column(MeetingReminderService::soon($events, $now, 15), 'id'));
	}
}
