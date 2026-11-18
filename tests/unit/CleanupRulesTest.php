<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\CleanupRules;
use PHPUnit\Framework\TestCase;

class CleanupRulesTest extends TestCase {
	public function testSetAndDecode(): void {
		$rules = CleanupRules::set([], 'JUNK', 30);
		$this->assertSame(['JUNK' => 30], $rules);
		$this->assertSame(['JUNK' => 90], CleanupRules::set($rules, 'JUNK', 90));
		$this->assertSame([], CleanupRules::set($rules, 'JUNK', 0), '« Jamais »');
		$this->assertSame(['A' => 7], CleanupRules::decode('{"A":7,"B":5,"C":"30","":30}'), 'only the durations offered');
		$this->assertSame([], CleanupRules::decode('nope'));
		$this->expectException(\InvalidArgumentException::class);
		CleanupRules::set([], 'X', 3);
	}

	public function testTooMany(): void {
		$rules = [];
		for ($i = 0; $i < CleanupRules::MAX_FOLDERS; $i++) {
			$rules = CleanupRules::set($rules, 'F' . $i, 30);
		}
		$this->assertSame(7, CleanupRules::set($rules, 'F0', 7)['F0'], 'a folder already there can change, even when full');
		$this->expectExceptionMessage('too many');
		CleanupRules::set($rules, 'NEW', 30);
	}

	public function testDeletable(): void {
		$now = new \DateTimeImmutable('2026-11-20T15:00:00Z');
		$cutoff = CleanupRules::cutoff(30, $now);
		$this->assertSame('2026-10-21T00:00:00+00:00', $cutoff->format('c'));
		$items = [
			['id' => 'a', 'date' => '2026-09-01T10:00:00Z', 'flagged' => false, 'isRead' => false],
			['id' => 'b', 'date' => '2026-09-02T10:00:00Z', 'flagged' => true],
			['id' => 'c', 'date' => '2026-09-03T10:00:00Z', 'flagged' => false],
			['id' => 'd', 'date' => '2026-11-01T10:00:00Z', 'flagged' => false],
			['id' => 'e', 'date' => '2026-09-04T10:00:00Z', 'flagged' => false],
		];
		$this->assertSame(['ids' => ['a'], 'done' => true], CleanupRules::deletable($items, $cutoff, ['c']), 'unread too; not followed, not pinned; stops at the first recent one');
		$this->assertSame(['ids' => ['a', 'c'], 'done' => false], CleanupRules::deletable(array_slice($items, 0, 3), $cutoff, []));
	}
}
