<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\ArchiveRules;
use PHPUnit\Framework\TestCase;

class ArchiveRulesTest extends TestCase {
	public function testTheArchiveFolder(): void {
		$folders = [
			['id' => 'IN', 'name' => 'Boîte de réception', 'parentId' => null, 'role' => 'inbox'],
			['id' => 'SUB', 'name' => 'Archives', 'parentId' => 'IN', 'role' => null],
			['id' => 'TOP', 'name' => ' archives ', 'parentId' => null, 'role' => null],
		];
		$this->assertSame('TOP', ArchiveRules::archiveFolder($folders));
		$this->assertNull(ArchiveRules::archiveFolder(array_slice($folders, 0, 2)), 'not one inside another folder');
		$folders[] = ['id' => 'EX', 'name' => 'Archiv', 'parentId' => null, 'role' => 'archive'];
		$this->assertSame('EX', ArchiveRules::archiveFolder($folders), 'Exchange\'s own first');
	}

	public function testOnlyTheOldReadMessagesNeitherFollowedNorPinned(): void {
		$cutoff = new \DateTimeImmutable('2026-05-16T00:00:00Z');
		$items = [
			['id' => 'a', 'date' => '2025-01-02T10:00:00Z', 'isRead' => true, 'flagged' => false],
			['id' => 'b', 'date' => '2025-02-02T10:00:00Z', 'isRead' => false, 'flagged' => false],
			['id' => 'c', 'date' => '2025-03-02T10:00:00Z', 'isRead' => true, 'flagged' => true],
			['id' => 'd', 'date' => '2025-04-02T10:00:00Z', 'isRead' => true, 'flagged' => false],
			['id' => 'e', 'date' => '2026-05-15T23:59:00Z', 'isRead' => true, 'flagged' => false],
		];
		$this->assertSame(['ids' => ['a', 'e'], 'done' => false], ArchiveRules::archivable($items, $cutoff, ['d']));
		$items[] = ['id' => 'f', 'date' => '2026-05-16T08:00:00Z', 'isRead' => true, 'flagged' => false];
		$items[] = ['id' => 'g', 'date' => '2024-01-01T08:00:00Z', 'isRead' => true, 'flagged' => false];
		$this->assertSame(['ids' => ['a', 'e'], 'done' => true], ArchiveRules::archivable($items, $cutoff, ['d']), 'sorted oldest first: stops at the first recent one');
	}

	public function testMonths(): void {
		$this->assertSame(6, ArchiveRules::months(6));
		$this->assertSame(0, ArchiveRules::months(5));
		$this->assertSame('2026-05-16', ArchiveRules::cutoff(6, new \DateTimeImmutable('2026-11-16T14:00:00Z'))->format('Y-m-d'));
	}
}
