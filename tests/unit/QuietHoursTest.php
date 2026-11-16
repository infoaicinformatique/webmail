<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\QuietHours;
use PHPUnit\Framework\TestCase;

class QuietHoursTest extends TestCase {
	private static function at(string $local): \DateTimeImmutable {
		return new \DateTimeImmutable($local, new \DateTimeZone('Europe/Paris'));
	}

	public function testOffByDefault(): void {
		$this->assertSame(QuietHours::DEFAULTS, QuietHours::decode(''));
		$this->assertFalse(QuietHours::isQuiet(QuietHours::DEFAULTS, self::at('2026-11-17 23:00')));
	}

	public function testOvernight(): void {
		$s = ['on' => true, 'from' => 19, 'to' => 8, 'weekend' => false];
		$this->assertTrue(QuietHours::isQuiet($s, self::at('2026-11-17 19:00')));
		$this->assertTrue(QuietHours::isQuiet($s, self::at('2026-11-17 23:59')));
		$this->assertTrue(QuietHours::isQuiet($s, self::at('2026-11-18 07:59')));
		$this->assertFalse(QuietHours::isQuiet($s, self::at('2026-11-18 08:00')));
		$this->assertFalse(QuietHours::isQuiet($s, self::at('2026-11-17 18:59')));
	}

	public function testWithinADay(): void {
		$s = ['on' => true, 'from' => 12, 'to' => 14, 'weekend' => false];
		$this->assertTrue(QuietHours::isQuiet($s, self::at('2026-11-17 13:30')));
		$this->assertFalse(QuietHours::isQuiet($s, self::at('2026-11-17 14:00')));
		$this->assertFalse(QuietHours::isQuiet(['on' => true, 'from' => 9, 'to' => 9, 'weekend' => false], self::at('2026-11-17 09:30')), 'no time');
	}

	public function testWeekend(): void {
		$s = ['on' => true, 'from' => 22, 'to' => 7, 'weekend' => true];
		$this->assertTrue(QuietHours::isQuiet($s, self::at('2026-11-21 15:00')), 'Saturday');
		$this->assertTrue(QuietHours::isQuiet($s, self::at('2026-11-22 10:00')), 'Sunday');
		$this->assertFalse(QuietHours::isQuiet($s, self::at('2026-11-20 15:00')), 'Friday');
		$this->assertFalse(QuietHours::isQuiet(['weekend' => false] + $s, self::at('2026-11-21 15:00')));
	}

	public function testWhatStillComesThrough(): void {
		$night = self::at('2026-11-17 23:00');
		$s = ['on' => true, 'from' => 19, 'to' => 8, 'weekend' => false, 'vip' => true, 'reminders' => false];
		$this->assertTrue(QuietHours::silences($s, $night, 'message'));
		$this->assertFalse(QuietHours::silences($s, $night, 'vip'));
		$this->assertTrue(QuietHours::silences($s, $night, 'reminder'));
		$this->assertFalse(QuietHours::silences($s, self::at('2026-11-17 10:00'), 'message'), 'outside: everything');
	}

	public function testNormalize(): void {
		$this->assertSame(['on' => true, 'from' => 19, 'to' => 6, 'weekend' => false, 'vip' => true, 'reminders' => true],
			QuietHours::normalize(['on' => 'true', 'from' => 42, 'to' => '6', 'weekend' => false, 'other' => 1]));
	}
}
