<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\NcCalendarRules;
use OCA\Webmail\Service\VCard;
use PHPUnit\Framework\TestCase;

/** The Nextcloud calendars and address books in the webmail: the rules without a server. */
class NextcloudSyncTest extends TestCase {
	public function testKeysAndIds(): void {
		$this->assertSame('nc:12', NcCalendarRules::key(12));
		$this->assertSame(12, NcCalendarRules::calendarId('nc:12'));
		$this->assertNull(NcCalendarRules::calendarId('nc:12x'));
		$this->assertNull(NcCalendarRules::calendarId('calendar'));
		$this->assertFalse(NcCalendarRules::isKey('shared:a@b.fr'));

		$this->assertSame('abc.ics', NcCalendarRules::eventId('abc.ics'));
		$this->assertSame('abc.ics|20261114T080000Z', NcCalendarRules::eventId('abc.ics', '20261114T080000Z'));
		$this->assertSame(['abc.ics', '20261114T080000Z'], NcCalendarRules::splitId('abc.ics|20261114T080000Z'));
		$this->assertSame(['abc.ics', '20261114'], NcCalendarRules::splitId('abc.ics|20261114'));
		$this->assertSame(['abc.ics', null], NcCalendarRules::splitId('abc.ics'));
		foreach (['../x.ics', 'a/b.ics', '', 'abc.ics|demain'] as $bad) {
			try {
				NcCalendarRules::splitId($bad);
				$this->fail('accepted: ' . $bad);
			} catch (MailException) {
				$this->addToAssertionCount(1);
			}
		}
		$paris = new \DateTimeImmutable('2026-11-14 09:00', new \DateTimeZone('Europe/Paris'));
		$this->assertSame('20261114T080000Z', NcCalendarRules::occurrence($paris, false));
		$this->assertSame('20261114', NcCalendarRules::occurrence($paris, true));
	}

	public function testColour(): void {
		$this->assertSame('#0082c9', NcCalendarRules::color('#0082C9FF'));
		$this->assertSame('#e9322d', NcCalendarRules::color('#e9322d'));
		$this->assertNull(NcCalendarRules::color('red'));
		$this->assertNull(NcCalendarRules::color(null));
	}

	public function testRuleWritten(): void {
		$tue = new \DateTimeImmutable('2026-11-10'); // 2nd Tuesday
		$this->assertSame('FREQ=DAILY', NcCalendarRules::rrule(['freq' => 'daily', 'interval' => 1], $tue, false));
		$this->assertSame('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;COUNT=10',
			NcCalendarRules::rrule(['freq' => 'weekly', 'interval' => 2, 'days' => ['Monday', 'Thursday'], 'count' => 10], $tue, false));
		$this->assertSame('FREQ=WEEKLY;BYDAY=TU', NcCalendarRules::rrule(['freq' => 'weekly', 'days' => []], $tue, false));
		$this->assertSame('FREQ=MONTHLY;BYMONTHDAY=10;UNTIL=20270630T235959Z',
			NcCalendarRules::rrule(['freq' => 'monthly', 'by' => 'date', 'until' => '2027-06-30'], $tue, false));
		$this->assertSame('FREQ=MONTHLY;BYDAY=2TU', NcCalendarRules::rrule(['freq' => 'monthly', 'by' => 'weekday'], $tue, false));
		$this->assertSame('FREQ=MONTHLY;BYDAY=-1MO', NcCalendarRules::rrule(['freq' => 'monthly', 'by' => 'last'], new \DateTimeImmutable('2026-11-30'), false));
		$this->assertSame('FREQ=YEARLY;BYMONTH=11;BYMONTHDAY=10;UNTIL=20301110',
			NcCalendarRules::rrule(['freq' => 'yearly', 'by' => 'date', 'until' => '2030-11-10'], $tue, true));
	}

	public function testRuleRead(): void {
		$tue = new \DateTimeImmutable('2026-11-10');
		$r = NcCalendarRules::recurrence('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH;COUNT=10', $tue);
		$this->assertSame(['weekly', 2, ['Monday', 'Thursday'], 10, null], [$r['freq'], $r['interval'], $r['days'], $r['count'], $r['until']]);
		$r = NcCalendarRules::recurrence('FREQ=MONTHLY;BYDAY=2TU;UNTIL=20270630T215959Z', $tue);
		$this->assertSame(['monthly', 'weekday', 'Second', '2027-06-30'], [$r['freq'], $r['by'], $r['weekIndex'], $r['until']]);
		$r = NcCalendarRules::recurrence('FREQ=MONTHLY;BYMONTHDAY=10', $tue);
		$this->assertSame(['monthly', 'date', 10], [$r['freq'], $r['by'], $r['dayOfMonth']]);
		$this->assertSame('last', NcCalendarRules::recurrence('FREQ=YEARLY;BYMONTH=11;BYDAY=-1TU', $tue)['by']);
		// what the form cannot show: kept as is
		foreach (['FREQ=MONTHLY;BYDAY=2TU,4TU', 'FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR', 'FREQ=WEEKLY;BYHOUR=9', 'FREQ=MONTHLY;BYMONTHDAY=1,15', 'FREQ=HOURLY', 'FREQ=YEARLY;BYMONTH=3'] as $rule) {
			$this->assertSame('other', NcCalendarRules::recurrence($rule, $tue)['freq'], $rule);
		}
		// written then read: the same repetition
		foreach ([['freq' => 'weekly', 'interval' => 3, 'days' => ['Friday']], ['freq' => 'monthly', 'by' => 'weekday'], ['freq' => 'yearly', 'by' => 'date', 'count' => 5]] as $rule) {
			$back = NcCalendarRules::recurrence(NcCalendarRules::rrule($rule, $tue, false), $tue);
			$this->assertSame($rule['freq'], $back['freq']);
		}
	}

	public function testReminders(): void {
		$this->assertSame(15, NcCalendarRules::reminderMinutes('-PT15M'));
		$this->assertSame(90, NcCalendarRules::reminderMinutes('-PT1H30M'));
		$this->assertSame(1440, NcCalendarRules::reminderMinutes('-P1D'));
		$this->assertSame(10080, NcCalendarRules::reminderMinutes('-P1W'));
		$this->assertSame(0, NcCalendarRules::reminderMinutes('PT0S'));
		$this->assertNull(NcCalendarRules::reminderMinutes('PT15M')); // after the start
		$this->assertNull(NcCalendarRules::reminderMinutes('20261114T080000Z'));
		foreach ([0, 5, 15, 60, 120, 1440, 2880, 90] as $m) {
			$this->assertSame($m, NcCalendarRules::reminderMinutes(NcCalendarRules::trigger($m)));
		}
		$this->assertSame('Free', NcCalendarRules::freeBusy('TRANSPARENT', ''));
		$this->assertSame('Tentative', NcCalendarRules::freeBusy('OPAQUE', 'TENTATIVE'));
		$this->assertSame('Busy', NcCalendarRules::freeBusy('', 'CONFIRMED'));
		$this->assertSame('Accept', NcCalendarRules::response('accepted'));
		$this->assertSame('Unknown', NcCalendarRules::response('NEEDS-ACTION'));
	}

	public function testCardChangedKeepsTheRest(): void {
		$card = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:abc-1\r\nFN:Marie Martin\r\nN:Martin;Marie;;;\r\nEMAIL;TYPE=work:marie@exemple.fr\r\nTEL;TYPE=cell:0611111111\r\n"
			. "CATEGORIES:Clients\r\nitem1.X-ABLABEL:Perso\r\nPHOTO:data:image/png;base64," . base64_encode("\x89PNG....") . "\r\nIMPP;X-SERVICE-TYPE=Matrix:matrix:u/marie:ex.org\r\nX-SOCIALPROFILE:https://exemple.fr/marie\r\nEND:VCARD\r\n";
		$out = VCard::merge($card, [
			'displayName' => 'Marie Durand', 'givenName' => 'Marie', 'surname' => 'Durand', 'company' => 'ACME', 'department' => '', 'jobTitle' => '',
			'emails' => ['marie.durand@acme.fr'], 'mobilePhone' => '0622222222', 'businessPhone' => '', 'homePhone' => '',
			'street' => '', 'city' => 'Nice', 'postalCode' => '', 'country' => '', 'notes' => '', 'matrix' => '', 'birthday' => '',
		]);
		$this->assertStringContainsString("VERSION:4.0\r\n", $out);
		$this->assertStringContainsString("FN:Marie Durand\r\n", $out);
		$this->assertStringContainsString('marie.durand@acme.fr', $out);
		$this->assertStringNotContainsString('marie@exemple.fr', $out);
		$this->assertStringNotContainsString('0611111111', $out);
		$this->assertStringNotContainsString('matrix:u/marie', $out); // the Matrix address was removed in the form
		foreach (['UID:abc-1', 'CATEGORIES:Clients', 'X-SOCIALPROFILE:https://exemple.fr/marie', 'item1.X-ABLABEL:Perso'] as $kept) {
			$this->assertStringContainsString($kept, $out);
		}
		$this->assertSame(1, substr_count($out, 'BEGIN:VCARD'));
		$this->assertTrue(VCard::hasPhoto($out));
		$parsed = VCard::parse($out)[0];
		$this->assertSame(['Marie Durand', ['marie.durand@acme.fr'], '0622222222', 'Nice'], [$parsed['displayName'], $parsed['emails'], $parsed['mobilePhone'], $parsed['city']]);

		$new = VCard::merge(null, ['displayName' => 'Paul', 'emails' => ['paul@x.fr']], 'uid-9');
		$this->assertStringContainsString("UID:uid-9\r\n", $new);
		$this->assertStringContainsString("VERSION:3.0\r\n", $new);
	}

	public function testPhotos(): void {
		$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat('x', 200);
		$v3 = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:A\r\nPHOTO;ENCODING=b;TYPE=JPEG:" . chunk_split(base64_encode($jpeg), 74, "\r\n ") . "\r\nEND:VCARD\r\n";
		$this->assertSame(['mime' => 'image/jpeg', 'data' => $jpeg], VCard::photo($v3));
		$this->assertNull(VCard::photo("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:A\r\nPHOTO;VALUE=uri:https://exemple.fr/a.jpg\r\nEND:VCARD\r\n"));
		$this->assertNull(VCard::photo("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:A\r\nEND:VCARD\r\n"));

		$png = "\x89PNG\r\n" . str_repeat('y', 300);
		$with = VCard::withPhoto("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:A\r\nPHOTO;ENCODING=b;TYPE=JPEG:" . base64_encode($jpeg) . "\r\nEND:VCARD\r\n", $png);
		$this->assertSame(['mime' => 'image/png', 'data' => $png], VCard::photo($with));
		$this->assertSame(1, substr_count($with, 'PHOTO'));
		$this->assertStringEndsWith("END:VCARD\r\n", $with);
		$this->assertFalse(VCard::hasPhoto(VCard::withPhoto($with, '')));
		$v4 = VCard::withPhoto("BEGIN:VCARD\r\nVERSION:4.0\r\nFN:A\r\nEND:VCARD\r\n", $png);
		$this->assertStringContainsString('PHOTO:data:image/png;base64,', str_replace("\r\n ", '', $v4));
	}
}
