<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\UnifiedSearchService;
use PHPUnit\Framework\TestCase;

class UnifiedSearchServiceTest extends TestCase {
	private \DateTimeZone $paris;
	private \DateTimeImmutable $now;

	protected function setUp(): void {
		$this->paris = new \DateTimeZone('Europe/Paris');
		$this->now = new \DateTimeImmutable('2026-11-12T15:00:00Z');
	}

	public function testMessagesOfSeveralAccountsNewestFirst(): void {
		$a = [['id' => 'A1', 'date' => '2026-11-12T10:00:00Z'], ['id' => 'A2', 'date' => '2026-11-01T10:00:00Z']];
		$b = [['id' => 'B1', 'date' => '2026-11-10T10:00:00Z']];
		$order = fn ($x, $y) => strcmp($y['date'], $x['date']);
		[$page, $more] = UnifiedSearchService::page([$a, $b], 0, 2, $order);
		$this->assertSame(['A1', 'B1'], array_column($page, 'id'));
		$this->assertTrue($more);
		[$page, $more] = UnifiedSearchService::page([$a, $b], 2, 2, $order);
		$this->assertSame(['A2'], array_column($page, 'id'));
		$this->assertFalse($more);
		$this->assertSame([[], false], UnifiedSearchService::page([], 0, 5, $order));
	}

	public function testUpcomingEventsFirstThenPastOnes(): void {
		$events = [
			['id' => 'past-old', 'start' => '2026-06-01T08:00:00Z', 'end' => '2026-06-01T09:00:00Z'],
			['id' => 'next-week', 'start' => '2026-11-19T08:00:00Z', 'end' => '2026-11-19T09:00:00Z'],
			['id' => 'now', 'start' => '2026-11-12T14:30:00Z', 'end' => '2026-11-12T15:30:00Z'],
			['id' => 'past-recent', 'start' => '2026-11-10T08:00:00Z', 'end' => '2026-11-10T09:00:00Z'],
			['id' => 'tomorrow', 'start' => '2026-11-13T08:00:00+01:00', 'end' => '2026-11-13T09:00:00+01:00'],
		];
		[$page] = UnifiedSearchService::page([$events], 0, 10, UnifiedSearchService::eventOrder($this->now));
		$this->assertSame(['now', 'tomorrow', 'next-week', 'past-recent', 'past-old'], array_column($page, 'id'));
	}

	public function testDayLabels(): void {
		$this->assertSame('Aujourd\'hui 11:05', UnifiedSearchService::day('2026-11-12T10:05:00Z', $this->paris, $this->now));
		$this->assertSame('Hier', UnifiedSearchService::day('2026-11-11T10:05:00Z', $this->paris, $this->now));
		$this->assertSame('3 nov.', UnifiedSearchService::day('2026-11-03T10:05:00Z', $this->paris, $this->now));
		$this->assertSame('24 déc. 2025', UnifiedSearchService::day('2025-12-24T10:05:00Z', $this->paris, $this->now));
	}

	public function testSublines(): void {
		$m = ['from' => ['name' => 'Jean Dupont', 'email' => 'jean@x.fr'], 'date' => '2026-11-03T10:05:00Z', 'email' => 'user@societe-a.fr'];
		$this->assertSame('Jean Dupont · 3 nov.', UnifiedSearchService::messageSubline($m, $this->paris, false, $this->now));
		$this->assertSame('Jean Dupont · 3 nov. · user@societe-a.fr', UnifiedSearchService::messageSubline($m, $this->paris, true, $this->now));
		$m['from'] = ['name' => '', 'email' => 'jean@x.fr'];
		$this->assertSame('jean@x.fr · 3 nov.', UnifiedSearchService::messageSubline($m, $this->paris, false, $this->now));

		$ev = ['start' => '2026-11-13T08:00:00Z', 'allDay' => false, 'location' => 'Salle Riviera', 'email' => 'user@societe-a.fr'];
		$this->assertSame('Demain 09:00 · Salle Riviera', UnifiedSearchService::eventSubline($ev, $this->paris, $this->now, false));
		$ev = ['start' => '2027-01-08T08:00:00Z', 'allDay' => false, 'location' => '', 'email' => 'user@societe-a.fr'];
		$this->assertSame('ven. 8 janv. 2027 09:00 · user@societe-a.fr', UnifiedSearchService::eventSubline($ev, $this->paris, $this->now, true));
	}
}
