<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\MailStats;
use PHPUnit\Framework\TestCase;

class MailStatsTest extends TestCase {
	private function msg(string $date, string $email = 'a@x.fr', string $conv = '', bool $read = true, string $to = ''): array {
		return ['date' => $date, 'from' => ['name' => strtoupper($email[0]), 'email' => $email], 'conversationId' => $conv, 'isRead' => $read, 'displayTo' => $to];
	}

	public function testDays(): void {
		$this->assertSame(7, MailStats::days(7));
		$this->assertSame(30, MailStats::days(12));
	}

	public function testCompute(): void {
		$tz = new \DateTimeZone('Europe/Paris');
		$now = new \DateTimeImmutable('2026-03-11T10:00:00Z'); // a Wednesday
		$received = [
			$this->msg('2026-03-11T08:00:00Z', 'a@x.fr', 'c1', false),
			$this->msg('2026-03-10T07:30:00Z', 'b@x.fr', 'c2'),
			$this->msg('2026-03-10T07:00:00Z', 'a@x.fr', 'c3'),
			$this->msg('2026-03-09T12:00:00Z', 'me@x.fr', 'c4'),
			// before the period
			$this->msg('2026-02-01T08:00:00Z', 'z@x.fr', 'c9', false),
		];
		$sent = [
			$this->msg('2026-03-11T09:00:00Z', 'me@x.fr', 'c1', true, 'Alice; Bob'),
			$this->msg('2026-03-10T09:30:00Z', 'me@x.fr', 'c2', true, 'Bob'),
			$this->msg('2026-03-10T10:00:00Z', 'me@x.fr', 'c5', true, 'Carl'),
		];
		$r = MailStats::compute($received, $sent, 7, $tz, $now, 'ME@x.fr');
		$this->assertSame(7, $r['days']);
		$this->assertSame(4, $r['received']);
		$this->assertSame(3, $r['sent']);
		$this->assertSame(1, $r['unread']);
		$this->assertCount(7, $r['perDay']);
		$this->assertSame(['date' => '2026-03-11', 'received' => 1, 'sent' => 1], $r['perDay'][6]);
		$this->assertSame(['date' => '2026-03-10', 'received' => 2, 'sent' => 2], $r['perDay'][5]);
		$this->assertSame('2026-03-05', $r['perDay'][0]['date']);
		// local hours (UTC+1): 9 h, 8 h 30, 8 h, 13 h
		$this->assertSame(2, $r['hours'][8]);
		$this->assertSame(1, $r['hours'][9]);
		$this->assertSame(1, $r['hours'][13]);
		// Monday 9, Tuesday 10, Wednesday 11
		$this->assertSame([1, 2, 1, 0, 0, 0, 0], $r['weekdays']);
		// oneself not counted
		$this->assertSame(['a@x.fr', 'b@x.fr'], array_column($r['topSenders'], 'email'));
		$this->assertSame(2, $r['topSenders'][0]['count']);
		$this->assertSame([['name' => 'Bob', 'count' => 2], ['name' => 'Alice', 'count' => 1], ['name' => 'Carl', 'count' => 1]], $r['topRecipients']);
		// answers: 60 min (c1), 120 min (c2); c5 no message received
		$this->assertSame(2, $r['replied']);
		$this->assertSame(90, $r['replyMedian']);
		$this->assertFalse($r['partial']);
	}

	public function testMedian(): void {
		$this->assertNull(MailStats::median([]));
		$this->assertSame(5, MailStats::median([9, 5, 1]));
		$this->assertSame(3, MailStats::median([1, 5]));
	}

	public function testReplyLongAfterIsNotOne(): void {
		$received = [$this->msg('2026-01-01T08:00:00Z', 'a@x.fr', 'c1')];
		$sent = [[$this->msg('2026-02-01T08:00:00Z', 'me@x.fr', 'c1'), strtotime('2026-02-01T08:00:00Z')]];
		$this->assertSame([], MailStats::replyDelays($received, $sent));
	}
}
