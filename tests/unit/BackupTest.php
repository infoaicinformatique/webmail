<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\Backup;
use PHPUnit\Framework\TestCase;

class BackupTest extends TestCase {
	public function testDecode(): void {
		$s = Backup::decode('nope');
		$this->assertFalse($s['on']);
		$this->assertSame(Backup::DEFAULT_PATH, $s['path']);
		$this->assertSame(['inbox', 'sentitems'], $s['folders']);
		$s = Backup::decode('{"on":true,"path":"../x","folders":["sentitems","junk"],"cursors":{"sentitems":{"at":"2026-01-01T00:00:00Z","ids":["a",3]},"junk":{"at":"x"}},"total":4}');
		$this->assertTrue($s['on']);
		$this->assertSame(Backup::DEFAULT_PATH, $s['path']);
		$this->assertSame(['sentitems'], $s['folders']);
		$this->assertSame(['sentitems' => ['at' => '2026-01-01T00:00:00Z', 'ids' => ['a']]], $s['cursors']);
		$this->assertSame(4, $s['total']);
	}

	public function testPath(): void {
		$this->assertSame('Documents/Courrier', Backup::normalizePath(' /Documents//Courrier/ '));
		$this->assertTrue(Backup::validPath('Documents/Courrier 2026'));
		foreach (['', '/', '../etc', 'a/../b', '.cache', 'a:b', str_repeat('a', 300)] as $bad) {
			$this->assertFalse(Backup::validPath($bad), $bad);
		}
	}

	public function testStart(): void {
		$now = new \DateTimeImmutable('2026-03-11T10:00:00+01:00');
		$this->assertSame('2026-03-11T09:00:00Z', Backup::start($now, 0));
		$this->assertSame('2026-02-09T09:00:00Z', Backup::start($now, 30));
		$this->assertSame('2026-03-11T09:00:00Z', Backup::start($now, 12));
	}

	public function testPendingAndAdvance(): void {
		$items = [
			['id' => 'c', 'subject' => 'C', 'date' => '2026-03-11T10:00:00Z'],
			['id' => 'b2', 'subject' => 'B2', 'date' => '2026-03-11T09:00:00Z'],
			['id' => 'b1', 'subject' => 'B1', 'date' => '2026-03-11T09:00:00Z'],
			['id' => 'a', 'subject' => 'A', 'date' => '2026-03-11T08:00:00Z'],
		];
		$cursor = ['at' => '2026-03-11T09:00:00Z', 'ids' => ['b1']];
		// oldest first, the one already saved at the same second left out
		$this->assertSame(['b2', 'c'], array_column(Backup::pending($items, $cursor), 'id'));
		$this->assertSame(['b2'], array_column(Backup::pending($items, $cursor, 1), 'id'));
		$cursor = Backup::advance($cursor, 'b2', '2026-03-11T09:00:00Z');
		$this->assertSame(['at' => '2026-03-11T09:00:00Z', 'ids' => ['b1', 'b2']], $cursor);
		$this->assertSame(['c'], array_column(Backup::pending($items, $cursor), 'id'));
		$cursor = Backup::advance($cursor, 'c', '2026-03-11T10:00:00Z');
		$this->assertSame(['at' => '2026-03-11T10:00:00Z', 'ids' => ['c']], $cursor);
		$this->assertSame([], Backup::pending($items, $cursor));
		// an older one does not move it back
		$this->assertSame($cursor, Backup::advance($cursor, 'a', '2026-03-11T08:00:00Z'));
	}

	public function testFolderFor(): void {
		$this->assertSame('Webmail/Sauvegarde/moi@societe.fr/Reçus/2026/2026-01',
			Backup::folderFor('/Webmail/Sauvegarde/', 'Moi@Societe.fr', 'inbox', '2025-12-31T23:30:00Z', new \DateTimeZone('Europe/Paris')));
		$this->assertSame('B/x@y.fr/Envoyés/2025/2025-12', Backup::folderFor('B', 'x@y.fr', 'sentitems', '2025-12-31T23:30:00Z', new \DateTimeZone('UTC')));
	}
}
