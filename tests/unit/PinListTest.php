<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\PinList;
use PHPUnit\Framework\TestCase;

class PinListTest extends TestCase {
	public function testTheLastPinnedFirstEachOnce(): void {
		$list = PinList::set([], 'A', true);
		$list = PinList::set($list, 'B', true);
		$list = PinList::set($list, 'A', true);
		$this->assertSame(['A', 'B'], $list);
		$this->assertSame(['B'], PinList::set($list, 'A', false));
		$this->assertSame(['A', 'B'], PinList::set($list, 'C', false));
	}

	public function testAFewOnly(): void {
		$list = array_map(fn ($i) => 'id' . $i, range(1, PinList::MAX));
		$this->expectException(MailException::class);
		PinList::set($list, 'one more', true);
	}

	public function testUnpinningWorksEvenWhenFull(): void {
		$list = array_map(fn ($i) => 'id' . $i, range(1, PinList::MAX));
		$this->assertCount(PinList::MAX - 1, PinList::set($list, 'id3', false));
	}

	public function testDecodeAndKeep(): void {
		$this->assertSame([], PinList::decode('nonsense'));
		$this->assertSame(['A', 'B'], PinList::decode('["A", "", 3, "B", "A"]'));
		// a message deleted or moved (another id) leaves the list
		$this->assertSame(['A', 'C'], PinList::keep(['A', 'B', 'C'], ['C', 'A']));
	}
}
