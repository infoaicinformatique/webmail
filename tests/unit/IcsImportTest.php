<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\IcsImport;
use PHPUnit\Framework\TestCase;

class IcsImportTest extends TestCase {
	private function import(string $events): array {
		return IcsImport::events("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//FR\r\n" . $events . "END:VCALENDAR\r\n", new \DateTimeZone('Europe/Paris'));
	}

	public function testAWebinarInUtcInTheUserTimeZone(): void {
		$e = $this->import("BEGIN:VEVENT\r\nUID:1\r\nDTSTART:20261203T130000Z\r\nDTEND:20261203T143000Z\r\nSUMMARY:Webinaire\\, sécurité\r\n"
			. "LOCATION:En ligne\r\nDESCRIPTION:Lien d'accès\\nhttps://webinaire.example/abc\r\n"
			. "BEGIN:VALARM\r\nTRIGGER:-PT15M\r\nDESCRIPTION:Rappel\r\nEND:VALARM\r\nEND:VEVENT\r\n");
		$this->assertSame([[
			'subject' => 'Webinaire, sécurité', 'start' => '2026-12-03T14:00:00', 'end' => '2026-12-03T15:30:00', 'allDay' => false,
			'location' => 'En ligne', 'body' => "Lien d'accès\nhttps://webinaire.example/abc", 'recurring' => false,
		]], $e);
	}

	public function testAnotherZoneAndFoldedLines(): void {
		$e = $this->import("BEGIN:VEVENT\r\nDTSTART;TZID=America/New_York:20260705T090000\r\nDURATION:PT1H30M\r\nSUMMARY:Conférence à\r\n  New York\r\nEND:VEVENT\r\n");
		$this->assertSame('Conférence à New York', $e[0]['subject']);
		$this->assertSame('2026-07-05T15:00:00', $e[0]['start']);
		$this->assertSame('2026-07-05T16:30:00', $e[0]['end']);
	}

	public function testOutlookZoneName(): void {
		$e = $this->import("BEGIN:VEVENT\r\nDTSTART;TZID=\"Romance Standard Time\":20261110T100000\r\nDTEND;TZID=\"Romance Standard Time\":20261110T110000\r\nSUMMARY:Point\r\nEND:VEVENT\r\n");
		$this->assertSame('2026-11-10T10:00:00', $e[0]['start']);
	}

	public function testWholeDaysWithTheLastDayItself(): void {
		$e = $this->import("BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20261224\r\nDTEND;VALUE=DATE:20261227\r\nSUMMARY:Congés\r\nRRULE:FREQ=YEARLY\r\nEND:VEVENT\r\n"
			. "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20261231\r\nSUMMARY:Réveillon\r\nEND:VEVENT\r\n"
			. "BEGIN:VEVENT\r\nDTSTART:20261201T100000Z\r\nSTATUS:CANCELLED\r\nSUMMARY:Annulé\r\nEND:VEVENT\r\n");
		$this->assertCount(2, $e);
		$this->assertSame(['2026-12-24', '2026-12-26', true, true], [$e[0]['start'], $e[0]['end'], $e[0]['allDay'], $e[0]['recurring']]);
		$this->assertSame(['2026-12-31', '2026-12-31'], [$e[1]['start'], $e[1]['end']]);
	}

	public function testNotACalendar(): void {
		$this->expectException(MailException::class);
		IcsImport::events('BEGIN:VCARD', new \DateTimeZone('UTC'));
	}

	public function testNoEvent(): void {
		$this->expectException(MailException::class);
		$this->import("BEGIN:VTODO\r\nSUMMARY:x\r\nEND:VTODO\r\n");
	}
}
