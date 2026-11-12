<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\DashboardService;
use PHPUnit\Framework\TestCase;

class DashboardServiceTest extends TestCase {
	private static function msg(string $id, string $subject, string $date, string $name = 'Jean Dupont'): array {
		return ['id' => $id, 'subject' => $subject, 'date' => $date, 'from' => ['name' => $name, 'email' => 'jean@x.fr']];
	}

	public function testUnreadOfAllAccountsNewestFirst(): void {
		$r = DashboardService::mergeUnread([
			['id' => 1, 'email' => 'a@x.fr', 'items' => [self::msg('A1', 'Devis', '2026-11-12T08:00:00Z'), self::msg('A2', '', '2026-11-10T08:00:00Z', '')]],
			['id' => 2, 'email' => 'b@x.fr', 'items' => [self::msg('B1', 'Facture', '2026-11-11T08:00:00Z')]],
		], 7);
		$this->assertSame(['A1', 'B1', 'A2'], array_column($r, 'id'));
		$this->assertSame([1, 2, 1], array_column($r, 'accountId'));
		$this->assertSame('(sans objet)', $r[2]['subject']);
		$this->assertSame('jean@x.fr', $r[2]['from'], 'the address when there is no name');
		$this->assertSame('Jean Dupont', $r[0]['from']);
		$this->assertCount(2, DashboardService::mergeUnread([['id' => 1, 'email' => 'a@x.fr', 'items' => [self::msg('1', 'a', '1'), self::msg('2', 'b', '2'), self::msg('3', 'c', '3')]]], 2));
	}

	public function testDateLabelsInTheUsersTimeZone(): void {
		$tz = new \DateTimeZone('Europe/Paris');
		$now = new \DateTimeImmutable('2026-11-12T10:00:00Z');
		$this->assertSame('Aujourd\'hui 15:30', DashboardService::label('2026-11-12T14:30:00Z', false, $tz, $now));
		$this->assertSame('Demain, toute la journée', DashboardService::label('2026-11-12T23:00:00Z', true, $tz, $now), 'midnight in Paris is tomorrow');
		$this->assertSame('jeu. 19 nov. 09:00', DashboardService::label('2026-11-19T08:00:00Z', false, $tz, $now));
	}
}
