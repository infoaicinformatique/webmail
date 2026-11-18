<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\Recurring;
use PHPUnit\Framework\TestCase;

class RecurringTest extends TestCase {
	private static function s(string $freq, string $time = '09:00', int $weekday = 1, int $monthday = 1): array {
		return Recurring::schedule(['freq' => $freq, 'time' => $time, 'weekday' => $weekday, 'monthday' => $monthday, 'tz' => 'Europe/Paris']);
	}

	public function testNext(): void {
		// Wednesday 18 November 2026, 10:00 in Paris (09:00 UTC)
		$now = new \DateTimeImmutable('2026-11-18T09:00:00Z');
		$this->assertSame('2026-11-19T08:00:00Z', Recurring::next(self::s('daily'), $now), 'today 09:00 is past: tomorrow, Paris time');
		$this->assertSame('2026-11-18T10:00:00Z', Recurring::next(self::s('daily', '11:00'), $now), 'later today');
		$this->assertSame('2026-11-23T08:00:00Z', Recurring::next(self::s('weekly', '09:00', 1), $now), 'next Monday');
		$this->assertSame('2026-12-05T08:00:00Z', Recurring::next(self::s('monthly', '09:00', 1, 5), $now));
		$this->assertSame('2026-11-20T08:00:00Z', Recurring::next(self::s('weekdays'), new \DateTimeImmutable('2026-11-19T09:00:00Z')), 'Thursday → Friday');
		$this->assertSame('2026-11-23T08:00:00Z', Recurring::next(self::s('weekdays'), new \DateTimeImmutable('2026-11-20T09:00:00Z')), 'Friday → Monday');
		// summer time: 09:00 in Paris is 07:00 UTC
		$this->assertSame('2027-07-02T07:00:00Z', Recurring::next(self::s('daily'), new \DateTimeImmutable('2027-07-01T08:00:00Z')));
	}

	public function testSchedule(): void {
		$this->assertSame(['freq' => 'weekly', 'weekday' => 1, 'monthday' => 1, 'time' => '07:30', 'tz' => 'UTC'],
			Recurring::schedule(['freq' => 'weekly', 'weekday' => 9, 'monthday' => 31, 'time' => '07:30', 'tz' => 'Nowhere/Here']));
		$this->expectException(\InvalidArgumentException::class);
		Recurring::schedule(['freq' => 'hourly', 'time' => '07:30']);
	}

	public function testBadTime(): void {
		$this->expectException(\InvalidArgumentException::class);
		Recurring::schedule(['freq' => 'daily', 'time' => '25:00']);
	}

	public function testDue(): void {
		$now = new \DateTimeImmutable('2026-11-18T09:00:00Z');
		$this->assertTrue(Recurring::due(['next' => '2026-11-18T08:59:00Z'], $now));
		$this->assertFalse(Recurring::due(['next' => '2026-11-18T08:59:00Z', 'paused' => true], $now));
		$this->assertFalse(Recurring::due(['next' => '2026-11-18T09:01:00Z'], $now));
		$this->assertSame([], Recurring::decode('[{"id":"x"}]'), 'incomplete');
	}
}
