<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\NoReply;
use PHPUnit\Framework\TestCase;

class NoReplyTest extends TestCase {
	private static function sent(string $id, string $conv, string $date, string $to = 'Client', bool $meeting = false): array {
		return ['id' => $id, 'conversationId' => $conv, 'date' => $date, 'displayTo' => $to, 'isMeeting' => $meeting, 'subject' => 'S ' . $id];
	}

	public function testCandidates(): void {
		$now = new \DateTimeImmutable('2026-11-20T12:00:00Z');
		$rows = NoReply::candidates([
			self::sent('old', 'c1', '2026-11-10T09:00:00Z'),
			self::sent('relance', 'c1', '2026-11-15T09:00:00Z'),
			self::sent('recent', 'c2', '2026-11-19T09:00:00Z'),
			self::sent('tooold', 'c3', '2026-10-01T09:00:00Z'),
			self::sent('meeting', 'c4', '2026-11-12T09:00:00Z', 'Client', true),
			self::sent('self', 'c5', '2026-11-12T09:00:00Z', 'me@x.fr'),
			self::sent('ignored', 'c6', '2026-11-12T09:00:00Z'),
			self::sent('noconv', '', '2026-11-13T09:00:00Z'),
		], $now, 3, ['ignored'], 'Me@x.fr');
		$this->assertSame(['relance', 'noconv'], array_column($rows, 'id'), 'the last one of a conversation, sent 3 to 30 days ago, to someone else');
		$this->assertSame(['recent', 'relance', 'noconv'], array_column(NoReply::candidates([
			self::sent('relance', 'c1', '2026-11-15T09:00:00Z'), self::sent('recent', 'c2', '2026-11-19T09:00:00Z'), self::sent('noconv', '', '2026-11-13T09:00:00Z'),
		], $now, 1, [], 'me@x.fr'), 'id'));
	}

	public function testAnswered(): void {
		$conv = [
			['from' => ['email' => 'me@x.fr'], 'date' => '2026-11-15T09:00:00Z'],
			['from' => ['email' => 'client@y.fr'], 'date' => '2026-11-14T09:00:00Z'],
		];
		$this->assertFalse(NoReply::answered($conv, '2026-11-15T09:00:00Z', 'ME@x.fr'), 'only an earlier message of theirs');
		$conv[] = ['from' => ['email' => 'client@y.fr'], 'date' => '2026-11-16T09:00:00Z'];
		$this->assertTrue(NoReply::answered($conv, '2026-11-15T09:00:00Z', 'me@x.fr'));
	}

	public function testDaysAgeAndIgnore(): void {
		$this->assertSame(3, NoReply::days(4));
		$this->assertSame(7, NoReply::days(7));
		$this->assertSame(5, NoReply::age('2026-11-15T12:00:00Z', new \DateTimeImmutable('2026-11-20T13:00:00Z')));
		$this->assertSame(['a', 'b'], NoReply::ignore(['b', 'a'], 'b'));
		$this->assertSame([], NoReply::decodeIgnored('nope'));
	}
}
