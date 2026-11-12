<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsCalendar;

class SchedulingTest extends EwsTestCase {
	public function testAvailabilityOfSeveralPeople(): void {
		$ok = fn (string $events) => '<FreeBusyResponse><ResponseMessage ResponseClass="Success"><ResponseCode>NoError</ResponseCode></ResponseMessage>'
			. '<FreeBusyView><FreeBusyViewType xmlns="http://schemas.microsoft.com/exchange/services/2006/types">FreeBusy</FreeBusyViewType>'
			. '<CalendarEventArray xmlns="http://schemas.microsoft.com/exchange/services/2006/types">' . $events . '</CalendarEventArray></FreeBusyView></FreeBusyResponse>';
		$ev = fn ($s, $e, $t) => '<CalendarEvent><StartTime>' . $s . '</StartTime><EndTime>' . $e . '</EndTime><BusyType>' . $t . '</BusyType></CalendarEvent>';
		$this->queue('<GetUserAvailabilityResponse xmlns="http://schemas.microsoft.com/exchange/services/2006/messages"><FreeBusyResponseArray>'
			. $ok($ev('2026-11-16T09:00:00', '2026-11-16T10:00:00', 'Busy') . $ev('2026-11-16T12:00:00', '2026-11-16T13:00:00', 'Free'))
			. '<FreeBusyResponse><ResponseMessage ResponseClass="Error"><MessageText>x</MessageText><ResponseCode>ErrorMailRecipientNotFound</ResponseCode></ResponseMessage></FreeBusyResponse>'
			. $ok($ev('2026-11-16T14:00:00', '2026-11-16T15:30:00', 'Tentative'))
			. '</FreeBusyResponseArray></GetUserAvailabilityResponse>');
		$people = (new EwsCalendar($this->client()))->availability(['Moi@x.fr', 'client@exterieur.example', 'paul@x.fr', 'moi@x.fr', 'pas une adresse'],
			new \DateTimeImmutable('2026-11-16T00:00:00Z'), new \DateTimeImmutable('2026-11-17T00:00:00Z'));
		$this->assertSame([
			['email' => 'moi@x.fr', 'busy' => [['start' => '2026-11-16T09:00:00Z', 'end' => '2026-11-16T10:00:00Z', 'type' => 'Busy']], 'error' => null],
			['email' => 'client@exterieur.example', 'busy' => [], 'error' => 'disponibilités inconnues (personne extérieure ou adresse inconnue)'],
			['email' => 'paul@x.fr', 'busy' => [['start' => '2026-11-16T14:00:00Z', 'end' => '2026-11-16T15:30:00Z', 'type' => 'Tentative']], 'error' => null],
		], $people);
		$this->assertSame(3, substr_count($this->lastRequest(), '<t:MailboxData>'));
		$this->assertStringContainsString('<t:RequestedView>FreeBusy</t:RequestedView>', $this->lastRequest());
	}

	public function testFirstFreeSlot(): void {
		$paris = new \DateTimeZone('Europe/Paris');
		$people = [
			['busy' => [['start' => '2026-11-16T08:00:00Z', 'end' => '2026-11-16T09:10:00Z', 'type' => 'Busy']]], // 9:00–10:10 Paris
			['busy' => [['start' => '2026-11-16T09:30:00Z', 'end' => '2026-11-16T10:00:00Z', 'type' => 'Tentative']]], // 10:30–11:00
		];
		$slot = fn ($from, $min, $people) => EwsCalendar::firstFreeSlot($people, new \DateTimeImmutable($from), new \DateTimeImmutable($from . ' +14 days'), $min, $paris)?->format('Y-m-d H:i');
		// Monday 8:50 Paris: 30 min fit at 8:50? on the quarter hour: 9:00 is busy → 10:15 (before 10:30)
		$this->assertSame('2026-11-16 10:15', $slot('2026-11-16T07:50:00Z', 15, $people));
		// an hour: not 10:15 (10:30 busy), 11:00
		$this->assertSame('2026-11-16 11:00', $slot('2026-11-16T07:50:00Z', 60, $people));
		// evening: next morning
		$this->assertSame('2026-11-17 08:00', $slot('2026-11-16T17:30:00Z', 60, []));
		// Friday 17:30: Monday 8:00
		$this->assertSame('2026-11-23 08:00', $slot('2026-11-20T16:30:00Z', 60, []));
		// longer than a working day: none
		$this->assertNull($slot('2026-11-16T07:00:00Z', 11 * 60, []));
	}
}
