<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * The Exchange events written as iCalendar, for the Calendar app of
 * Nextcloud (and the phones synced with it). Pure functions, so the rules
 * are tested without Nextcloud.
 */
class ExchangeIcs {
	/** The UID of an event: the same every time, unique per occurrence. */
	public static function uid(string $itemId): string {
		return 'webmail-' . substr(sha1($itemId), 0, 32);
	}

	/**
	 * One VCALENDAR with the events (cancelled ones left out).
	 * @param list<array<string, mixed>> $events as EwsCalendar::findEvents gives them
	 * @param string $link the address of the webmail ending with « event= », to open an event there
	 */
	public static function calendar(array $events, \DateTimeZone $tz, string $link = '', ?\DateTimeImmutable $now = null): string {
		$stamp = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
		$out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Webmail Exchange//Nextcloud//FR\r\nCALSCALE:GREGORIAN\r\n";
		foreach ($events as $e) {
			$vevent = self::event($e, $tz, $link, $stamp);
			if ($vevent !== null) {
				$out .= $vevent;
			}
		}
		return $out . "END:VCALENDAR\r\n";
	}

	/** @param array<string, mixed> $e */
	public static function event(array $e, \DateTimeZone $tz, string $link, string $stamp): ?string {
		$id = (string)($e['id'] ?? '');
		if ($id === '' || !empty($e['isCancelled']) || empty($e['start']) || empty($e['end'])) {
			return null;
		}
		try {
			$start = new \DateTimeImmutable((string)$e['start']);
			$end = new \DateTimeImmutable((string)$e['end']);
		} catch (\Exception $ex) {
			return null;
		}
		$lines = ['BEGIN:VEVENT', 'UID:' . self::uid($id), 'DTSTAMP:' . $stamp];
		if (!empty($e['allDay'])) {
			// the days of the user's time zone (Exchange gives them as UTC instants)
			$first = $start->setTimezone($tz)->format('Ymd');
			$last = $end->setTimezone($tz)->format('Ymd');
			if ($last <= $first) {
				$last = $start->setTimezone($tz)->modify('+1 day')->format('Ymd');
			}
			$lines[] = 'DTSTART;VALUE=DATE:' . $first;
			$lines[] = 'DTEND;VALUE=DATE:' . $last;
		} else {
			$utc = new \DateTimeZone('UTC');
			$lines[] = 'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z');
			$lines[] = 'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z');
		}
		$lines[] = 'SUMMARY:' . self::esc((string)($e['subject'] ?? '') !== '' ? (string)$e['subject'] : '(sans objet)');
		if (($e['location'] ?? '') !== '') {
			$lines[] = 'LOCATION:' . self::esc((string)$e['location']);
		}
		$organizer = $e['organizer'] ?? null;
		if (is_array($organizer) && ($organizer['email'] ?? '') !== '' && !empty($e['isMeeting'])) {
			$cn = (string)($organizer['name'] ?? '');
			$lines[] = 'ORGANIZER' . ($cn !== '' ? ';CN="' . str_replace(['"', "\r", "\n"], ['\'', '', ''], $cn) . '"' : '') . ':mailto:' . self::esc((string)$organizer['email']);
		}
		$free = ($e['freeBusy'] ?? 'Busy') === 'Free';
		$lines[] = 'TRANSP:' . ($free ? 'TRANSPARENT' : 'OPAQUE');
		if (($e['freeBusy'] ?? '') === 'Tentative' || ($e['response'] ?? '') === 'Tentative') {
			$lines[] = 'STATUS:TENTATIVE';
		}
		if ($link !== '') {
			$lines[] = 'URL:' . $link . rawurlencode($id);
		}
		$lines[] = 'END:VEVENT';
		$out = '';
		foreach ($lines as $l) {
			$out .= self::fold($l) . "\r\n";
		}
		return $out;
	}

	private static function esc(string $s): string {
		$s = (string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $s);
		return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $s);
	}

	/** Lines of 75 bytes at most, without cutting a character. */
	private static function fold(string $line): string {
		$out = '';
		while (strlen($line) > 75) {
			$cut = 75;
			while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
				$cut--;
			}
			$out .= substr($line, 0, $cut) . "\r\n ";
			$line = substr($line, $cut);
		}
		return $out . $line;
	}
}
