<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\Digest;
use PHPUnit\Framework\TestCase;

class DigestTest extends TestCase {
	private static function at(string $local): \DateTimeImmutable {
		return new \DateTimeImmutable($local, new \DateTimeZone('Europe/Paris'));
	}

	public function testDueOnceADayFromItsHour(): void {
		$this->assertFalse(Digest::due(self::at('2026-11-17 07:59'), 8, ''), 'not before its hour');
		$this->assertTrue(Digest::due(self::at('2026-11-17 08:00'), 8, ''));
		$this->assertTrue(Digest::due(self::at('2026-11-17 10:59'), 8, '2026-11-16'), 'a little late: still');
		$this->assertFalse(Digest::due(self::at('2026-11-17 11:00'), 8, '2026-11-16'), 'too late: not in the evening');
		$this->assertFalse(Digest::due(self::at('2026-11-17 08:15'), 8, '2026-11-17'), 'once a day');
		$this->assertFalse(Digest::due(self::at('2026-11-17 08:15'), 0, ''), 'off');
		$this->assertFalse(Digest::due(self::at('2026-11-17 08:15'), 3, ''), 'not an hour offered');
	}

	public function testValidHours(): void {
		$this->assertTrue(Digest::validHour(0));
		$this->assertTrue(Digest::validHour(7));
		$this->assertFalse(Digest::validHour(23));
	}

	public function testCounts(): void {
		$now = new \DateTimeImmutable('2026-11-17T08:00:00Z');
		$today = [
			'meetings' => [
				['subject' => 'Séminaire', 'start' => '2026-11-17', 'allDay' => true, 'over' => false],
				['subject' => 'Petit-déjeuner', 'start' => '2026-11-17T07:00:00Z', 'allDay' => false, 'over' => true],
				['subject' => 'Point projet', 'start' => '2026-11-17T08:30:00Z', 'allDay' => false, 'over' => false],
				['subject' => 'Client B', 'start' => '2026-11-17T14:00:00Z', 'allDay' => false, 'over' => false],
			],
			'tasks' => [['id' => 't']],
			'flagged' => [],
			'toReply' => [['id' => 'r1'], ['id' => 'r2']],
		];
		$c = Digest::counts($today, 12, 2, $now);
		$this->assertSame(['meetings' => 3, 'first' => 'Point projet', 'firstStart' => '2026-11-17T08:30:00Z', 'unread' => 12, 'important' => 2,
			'toReply' => 2, 'tasks' => 1, 'flagged' => 0], $c, 'a meeting over is not counted; the next one with an hour named');
		$this->assertFalse(Digest::isEmpty($c));
		$this->assertSame(1, Digest::counts([], 1, 5, $now)['important'], 'no more important than unread');
		$this->assertTrue(Digest::isEmpty(Digest::counts([], 0, 0, $now)));
	}

	public function testImportant(): void {
		$this->assertTrue(Digest::important(['importance' => 'High', 'from' => ['email' => 'x@y.fr']], []));
		$this->assertTrue(Digest::important(['importance' => 'Normal', 'from' => ['email' => 'Boss@y.fr']], ['boss@y.fr']));
		$this->assertFalse(Digest::important(['importance' => 'Normal', 'from' => ['email' => 'x@y.fr']], ['boss@y.fr']));
	}
}
