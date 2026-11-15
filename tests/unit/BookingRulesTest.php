<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\BookingRules;
use OCA\Webmail\Service\BookingService;
use PHPUnit\Framework\TestCase;

class BookingRulesTest extends TestCase {
	public function testTheTokenNamesItsOwnerAndASecret(): void {
		$token = BookingRules::token('marie.martin@pbcannes', str_repeat('a', 24));
		$this->assertSame(['marie.martin@pbcannes', str_repeat('a', 24)], BookingRules::parse($token));
		$this->assertNull(BookingRules::parse('bWFyaWU'), 'no secret');
		$this->assertNull(BookingRules::parse('bWFyaWU.short'));
		$this->assertNull(BookingRules::parse('../etc.' . str_repeat('a', 24)));
	}

	public function testFreeSlotsSpreadOverTheWorkingDays(): void {
		$zone = new \DateTimeZone('Europe/Paris');
		// Friday 13 November 2026, 16:00 in Paris
		$now = new \DateTimeImmutable('2026-11-13T15:00:00Z');
		$busy = [
			// Monday morning taken until 10:30
			['start' => '2026-11-16T08:00:00Z', 'end' => '2026-11-16T09:30:00Z'],
		];
		$slots = BookingRules::slots($busy, $now, $zone, 6, 30, 4);
		$local = array_map(fn ($s) => (new \DateTimeImmutable($s['start']))->setTimezone($zone)->format('D H:i'), $slots);
		// Friday is over (2 hours of notice: 18:00), the weekend skipped; the first free slot of each day
		$this->assertSame(['Mon 10:30', 'Tue 09:00', 'Wed 09:00', 'Thu 09:00'], $local);
		$this->assertSame('2026-11-16T10:00:00Z', $slots[0]['end']);
	}

	public function testMoreSlotsThanDaysTakesTheNextOnesOfEachDay(): void {
		$zone = new \DateTimeZone('UTC');
		$now = new \DateTimeImmutable('2026-11-16T05:00:00Z'); // a Monday
		// Monday and Tuesday: the first of each day, then the next ones (an hour apart)
		$slots = BookingRules::slots([], $now, $zone, 1, 60, 3);
		$this->assertSame(['2026-11-16T09:00:00Z', '2026-11-16T10:00:00Z', '2026-11-17T09:00:00Z'], array_column($slots, 'start'));
		// both days taken: nothing
		$this->assertSame([], BookingRules::slots([['start' => '2026-11-16T00:00:00Z', 'end' => '2026-11-18T00:00:00Z']], $now, $zone, 1, 30, 3));
	}

	public function testBusyTimesLeaveOutTheFreeAndTheCancelled(): void {
		$busy = BookingService::busy([
			['start' => '2026-11-16T09:00:00Z', 'end' => '2026-11-16T10:00:00Z', 'freeBusy' => 'Busy', 'isCancelled' => false],
			['start' => '2026-11-16T11:00:00Z', 'end' => '2026-11-16T12:00:00Z', 'freeBusy' => 'Free', 'isCancelled' => false],
			['start' => '2026-11-16T13:00:00Z', 'end' => '2026-11-16T14:00:00Z', 'freeBusy' => 'Busy', 'isCancelled' => true],
			['start' => '2026-11-16T15:00:00Z', 'end' => '2026-11-16T16:00:00Z', 'freeBusy' => 'OOF', 'isCancelled' => false],
		]);
		$this->assertSame(['2026-11-16T09:00:00Z', '2026-11-16T15:00:00Z'], array_column($busy, 'start'));
	}
}
