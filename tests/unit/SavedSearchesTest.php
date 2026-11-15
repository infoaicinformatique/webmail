<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\SavedSearches;
use PHPUnit\Framework\TestCase;

class SavedSearchesTest extends TestCase {
	public function testASearchFindingNothingParticularIsNotKept(): void {
		$this->assertNull(SavedSearches::normalize(['name' => 'Tout', 'q' => '  ']));
		$this->assertNotNull(SavedSearches::normalize(['flagged' => true]));
	}

	public function testItIsNormalized(): void {
		$n = SavedSearches::normalize(['q' => ' facture ', 'since' => '12', 'unread' => '1', 'folder' => '']);
		$this->assertSame('facture', $n['q']);
		$this->assertSame('facture', $n['name']);
		$this->assertSame('', $n['since']);
		$this->assertTrue($n['unread']);
		$this->assertSame('inbox', $n['folder']);
		$this->assertFalse($n['all']);
	}

	public function testTheSameNameReplacesTheSearchInItsPlace(): void {
		$list = SavedSearches::add([], ['name' => 'Factures', 'q' => 'facture'], 'aaaaaaaa1');
		$list = SavedSearches::add($list, ['name' => 'Clients', 'q' => 'client'], 'bbbbbbbb2');
		$list = SavedSearches::add($list, ['name' => 'factures', 'q' => 'facture OR devis'], 'cccccccc3');
		$this->assertCount(2, $list);
		$this->assertSame('aaaaaaaa1', $list[0]['id']);
		$this->assertSame('facture OR devis', $list[0]['q']);
		$this->assertSame([['id' => 'bbbbbbbb2'] + SavedSearches::normalize(['name' => 'Clients', 'q' => 'client'])], SavedSearches::remove($list, 'aaaaaaaa1'));
	}

	public function testThereIsALimit(): void {
		$list = [];
		for ($i = 0; $i < SavedSearches::MAX; $i++) {
			$list = SavedSearches::add($list, ['name' => 'R' . $i, 'q' => 'x' . $i], sprintf('id%06d', $i));
		}
		$this->expectException(\InvalidArgumentException::class);
		SavedSearches::add($list, ['name' => 'Une de trop', 'q' => 'y'], 'idtoomany');
	}

	public function testDecodeLeavesBadEntriesOut(): void {
		$json = json_encode([['id' => 'abcdefgh12', 'q' => 'a'], ['id' => 'x', 'q' => 'b'], ['id' => 'abcdefgh13'], 'z']);
		$list = SavedSearches::decode($json);
		$this->assertCount(1, $list);
		$this->assertSame('abcdefgh12', $list[0]['id']);
		$this->assertSame([], SavedSearches::decode('not json'));
	}

	public function testTheCounterAsksForUnreadMessages(): void {
		$f = SavedSearches::filters(['since' => '7', 'attachments' => true], true);
		$this->assertTrue($f['unread']);
		$this->assertTrue($f['attachments']);
		$this->assertSame((new \DateTimeImmutable('today'))->modify('-7 days')->format('Y-m-d'), $f['since']);
		$this->assertFalse(SavedSearches::filters([], false)['unread']);
	}
}
