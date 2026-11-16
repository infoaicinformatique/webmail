<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\Newsletters;
use PHPUnit\Framework\TestCase;

class NewslettersTest extends TestCase {
	private static function m(string $id, string $email, bool $read, int $size = 100, string $date = '2026-11-01T00:00:00Z', string $method = 'mailto'): array {
		return ['id' => $id, 'email' => $email, 'name' => 'Nom ' . $id, 'isRead' => $read, 'size' => $size, 'date' => $date, 'method' => $method];
	}

	public function testOneRowPerSenderMostMessagesFirst(): void {
		$rows = Newsletters::group([
			self::m('a1', 'promo@shop.fr', false, 500, '2026-11-10T00:00:00Z', 'oneclick'),
			self::m('n1', 'News@Exemple.org', true, 100, '2026-11-09T00:00:00Z'),
			self::m('a2', 'promo@shop.fr', false, 500),
			self::m('n2', 'news@exemple.org', false, 100),
			self::m('a3', 'promo@shop.fr', true, 500),
			self::m('x1', 'rare@ici.fr', true, 9000),
		]);
		$this->assertSame(['promo@shop.fr', 'news@exemple.org', 'rare@ici.fr'], array_column($rows, 'email'));
		$this->assertSame(3, $rows[0]['count']);
		$this->assertSame(2, $rows[0]['unread']);
		$this->assertSame(1500, $rows[0]['bytes']);
		$this->assertSame(33, $rows[0]['readRate']);
		$this->assertSame('a1', $rows[0]['itemId'], 'the newest message tells how to leave');
		$this->assertSame('oneclick', $rows[0]['method']);
		$this->assertSame('2026-11-10T00:00:00Z', $rows[0]['last']);
		$this->assertSame(50, $rows[1]['readRate'], 'the same address in another case is the same list');
		$this->assertSame(100, $rows[2]['readRate']);
	}

	public function testEqualCountsTheHeaviestFirst(): void {
		$rows = Newsletters::group([self::m('a', 'a@x.fr', true, 10), self::m('b', 'b@x.fr', true, 20)]);
		$this->assertSame(['b@x.fr', 'a@x.fr'], array_column($rows, 'email'));
	}

	public function testKeptListsAreNotShown(): void {
		$rows = Newsletters::group([self::m('a', 'a@x.fr', true), self::m('b', 'b@x.fr', true)], ['a@x.fr']);
		$this->assertSame(['b@x.fr'], array_column($rows, 'email'));
		$this->assertSame([], Newsletters::group([]));
	}

	public function testKeepAndForget(): void {
		$kept = Newsletters::keep([], ' A@X.fr ', true);
		$this->assertSame(['a@x.fr'], $kept);
		$this->assertSame(['a@x.fr'], Newsletters::keep($kept, 'a@x.fr', true), 'once');
		$this->assertSame([], Newsletters::keep($kept, 'a@x.fr', false));
		$this->assertSame(['a@x.fr', 'b@x.fr'], Newsletters::decodeKept('["a@x.fr","B@x.fr","a@x.fr","pas une adresse",3]'));
		$this->assertSame([], Newsletters::decodeKept('nope'));
		$this->expectException(\InvalidArgumentException::class);
		Newsletters::keep([], 'pas une adresse', true);
	}

	public function testTheOldestKeptIsForgottenWhenFull(): void {
		$kept = [];
		for ($i = 0; $i <= Newsletters::KEPT_MAX; $i++) {
			$kept = Newsletters::keep($kept, 'l' . $i . '@x.fr', true);
		}
		$this->assertCount(Newsletters::KEPT_MAX, $kept);
		$this->assertSame('l1@x.fr', $kept[0]);
	}
}
