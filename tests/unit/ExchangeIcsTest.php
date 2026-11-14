<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\ExchangeIcs;
use PHPUnit\Framework\TestCase;

class ExchangeIcsTest extends TestCase {
	private function ics(array $events): string {
		return ExchangeIcs::calendar($events, new \DateTimeZone('Europe/Paris'), 'https://cloud.example/apps/webmail/?account=3&module=calendar&event=',
			new \DateTimeImmutable('2026-11-14T08:00:00Z'));
	}

	public function testAMeeting(): void {
		$ics = $this->ics([[
			'id' => 'AAMk/1+x=', 'subject' => 'Comité de pilotage, projet A; lot 2', 'start' => '2026-11-16T09:00:00Z', 'end' => '2026-11-16T10:30:00Z',
			'location' => 'Salle Riviera', 'isMeeting' => true, 'organizer' => ['name' => 'Paul "PD" Durand', 'email' => 'paul@societe-a.fr'], 'freeBusy' => 'Busy',
		]]);
		$this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
		$this->assertStringContainsString("UID:" . ExchangeIcs::uid('AAMk/1+x=') . "\r\nDTSTAMP:20261114T080000Z\r\n", $ics);
		$this->assertStringContainsString("DTSTART:20261116T090000Z\r\nDTEND:20261116T103000Z\r\n", $ics);
		$this->assertStringContainsString('SUMMARY:Comité de pilotage\, projet A\; lot 2', $ics);
		$this->assertStringContainsString('LOCATION:Salle Riviera', $ics);
		$this->assertStringContainsString('ORGANIZER;CN="Paul \'PD\' Durand":mailto:paul@societe-a.fr', $ics);
		$this->assertStringContainsString('TRANSP:OPAQUE', $ics);
		$this->assertStringContainsString('URL:https://cloud.example/apps/webmail/?account=3&module=calendar&event=AAMk%2F1%2Bx%3D', str_replace("\r\n ", '', $ics));
		$this->assertStringEndsWith("END:VEVENT\r\nEND:VCALENDAR\r\n", $ics);
	}

	public function testAllDayInTheUserTimeZone(): void {
		// Exchange: midnight in Paris, as UTC instants
		$ics = $this->ics([['id' => 'a', 'subject' => 'Congés', 'start' => '2026-11-19T23:00:00Z', 'end' => '2026-11-21T23:00:00Z', 'allDay' => true, 'freeBusy' => 'OOF']]);
		$this->assertStringContainsString("DTSTART;VALUE=DATE:20261120\r\nDTEND;VALUE=DATE:20261122\r\n", $ics);
	}

	public function testFreeTentativeAndCancelled(): void {
		$ics = $this->ics([
			['id' => 'f', 'subject' => 'Info', 'start' => '2026-11-16T09:00:00Z', 'end' => '2026-11-16T10:00:00Z', 'freeBusy' => 'Free'],
			['id' => 't', 'subject' => 'Peut-être', 'start' => '2026-11-16T09:00:00Z', 'end' => '2026-11-16T10:00:00Z', 'response' => 'Tentative'],
			['id' => 'c', 'subject' => 'Annulé', 'start' => '2026-11-16T09:00:00Z', 'end' => '2026-11-16T10:00:00Z', 'isCancelled' => true],
			['subject' => 'Sans id', 'start' => '2026-11-16T09:00:00Z', 'end' => '2026-11-16T10:00:00Z'],
		]);
		$this->assertStringContainsString('TRANSP:TRANSPARENT', $ics);
		$this->assertStringContainsString('STATUS:TENTATIVE', $ics);
		$this->assertStringNotContainsString('Annulé', $ics);
		$this->assertStringNotContainsString('Sans id', $ics);
		$this->assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
	}

	public function testLongLinesAreFoldedWithoutCuttingACharacter(): void {
		$ics = $this->ics([['id' => 'l', 'subject' => str_repeat('é', 60), 'start' => '2026-11-16T09:00:00Z', 'end' => '2026-11-16T10:00:00Z']]);
		foreach (explode("\r\n", $ics) as $line) {
			$this->assertLessThanOrEqual(75, strlen($line));
			$this->assertTrue(mb_check_encoding($line, 'UTF-8'), $line);
		}
		$this->assertStringContainsString('SUMMARY:' . str_repeat('é', 60), str_replace("\r\n ", '', $ics));
	}

	public function testNoNewLineInjection(): void {
		$ics = $this->ics([['id' => 'n', 'subject' => "Réunion\r\nATTENDEE:mailto:x@y", 'start' => '2026-11-16T09:00:00Z', 'end' => '2026-11-16T10:00:00Z']]);
		$this->assertStringNotContainsString("\r\nATTENDEE", $ics);
		$this->assertStringContainsString('SUMMARY:Réunion\nATTENDEE:mailto:x@y', $ics);
	}
}
