<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Exception\MailException;

/**
 * « Ajouter à mon agenda » for an .ics file received (a ticket, a booking,
 * a webinar): its events as the calendar form takes them, in the user's
 * time zone. Pure functions, so the rules are tested without Exchange.
 */
class IcsImport {
	/** at most this many events from one file */
	public const MAX = 20;

	/**
	 * @return list<array{subject: string, start: string, end: string, allDay: bool, location: string, body: string, recurring: bool}>
	 * @throws MailException
	 */
	public static function events(string $ics, \DateTimeZone $tz): array {
		$lines = self::unfold($ics);
		if (!in_array('BEGIN:VCALENDAR', array_map('strtoupper', $lines), true)) {
			throw new MailException('Ce fichier n\'est pas un agenda (iCalendar)');
		}
		$events = [];
		$cur = null;
		$depth = 0;
		foreach ($lines as $line) {
			$upper = strtoupper($line);
			if ($upper === 'BEGIN:VEVENT') {
				$cur = [];
				$depth = 0;
				continue;
			}
			if ($cur === null) {
				continue;
			}
			// (an alarm inside an event: its lines are not the event's)
			if (str_starts_with($upper, 'BEGIN:')) {
				$depth++;
				continue;
			}
			if (str_starts_with($upper, 'END:') && $upper !== 'END:VEVENT') {
				$depth--;
				continue;
			}
			if ($upper === 'END:VEVENT') {
				$e = self::event($cur, $tz);
				if ($e !== null) {
					$events[] = $e;
				}
				$cur = null;
				if (count($events) >= self::MAX) {
					break;
				}
				continue;
			}
			if ($depth > 0) {
				continue;
			}
			[$name, $params, $value] = self::property($line);
			$cur[$name] ??= [$params, $value];
		}
		if ($events === []) {
			throw new MailException('Aucun événement dans ce fichier');
		}
		return $events;
	}

	/** @param array<string, array{0: array<string, string>, 1: string}> $p */
	private static function event(array $p, \DateTimeZone $tz): ?array {
		if (!isset($p['DTSTART']) || strtoupper($p['STATUS'][1] ?? '') === 'CANCELLED') {
			return null;
		}
		[$start, $allDay] = self::time($p['DTSTART'], $tz);
		if (isset($p['DTEND'])) {
			[$end] = self::time($p['DTEND'], $tz);
		} elseif (isset($p['DURATION'])) {
			$end = $start->add(self::duration($p['DURATION'][1]));
		} else {
			$end = $allDay ? $start->modify('+1 day') : $start;
		}
		if ($allDay) {
			// the calendar form takes the last day itself (iCalendar: the day after)
			$last = $end > $start ? $end->modify('-1 day') : $start;
			return self::out($p, $start->format('Y-m-d'), $last->format('Y-m-d'), true);
		}
		if ($end <= $start) {
			$end = $start->modify('+30 minutes');
		}
		return self::out($p, $start->format('Y-m-d\TH:i:s'), $end->format('Y-m-d\TH:i:s'), false);
	}

	private static function out(array $p, string $start, string $end, bool $allDay): array {
		$text = fn (string $k) => trim(self::unescape($p[$k][1] ?? ''));
		$body = $text('DESCRIPTION');
		$url = $text('URL');
		if ($url !== '' && !str_contains($body, $url)) {
			$body = trim($body . "\n\n" . $url);
		}
		return [
			'subject' => mb_substr($text('SUMMARY') !== '' ? $text('SUMMARY') : '(sans titre)', 0, 255),
			'start' => $start,
			'end' => $end,
			'allDay' => $allDay,
			'location' => mb_substr($text('LOCATION'), 0, 255),
			'body' => mb_substr($body, 0, 20000),
			'recurring' => isset($p['RRULE']),
		];
	}

	/**
	 * A date-time in the user's time zone; a date alone is a whole day.
	 * @param array{0: array<string, string>, 1: string} $prop
	 * @return array{0: \DateTimeImmutable, 1: bool}
	 */
	private static function time(array $prop, \DateTimeZone $tz): array {
		[$params, $value] = $prop;
		$value = trim($value);
		if (($params['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $value)) {
			$d = \DateTimeImmutable::createFromFormat('!Ymd', substr($value, 0, 8), $tz);
			if ($d === false) {
				throw new MailException('Date invalide dans le fichier : %s', [$value]);
			}
			return [$d, true];
		}
		if (!preg_match('/^(\d{8}T\d{4,6})(Z?)$/', $value, $m)) {
			throw new MailException('Date invalide dans le fichier : %s', [$value]);
		}
		$zone = $tz;
		if ($m[2] === 'Z') {
			$zone = new \DateTimeZone('UTC');
		} elseif (isset($params['TZID'])) {
			$zone = self::zone($params['TZID']) ?? $tz;
		}
		$format = strlen($m[1]) === 13 ? '!Ymd\THi' : '!Ymd\THis';
		$d = \DateTimeImmutable::createFromFormat($format, $m[1], $zone);
		if ($d === false) {
			throw new MailException('Date invalide dans le fichier : %s', [$value]);
		}
		return [$d->setTimezone($tz), false];
	}

	private static function zone(string $id): ?\DateTimeZone {
		$id = trim($id, '"/ ');
		try {
			return new \DateTimeZone($id);
		} catch (\Exception $e) {
			// Outlook's names (« Romance Standard Time »)
			$iana = \OCA\Webmail\Ews\TimeZones::toIana($id);
			try {
				return $iana !== null ? new \DateTimeZone($iana) : null;
			} catch (\Exception $e) {
				return null;
			}
		}
	}

	private static function duration(string $v): \DateInterval {
		if (preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', trim($v), $m)) {
			$days = (int)($m[2] ?? 0) * 7 + (int)($m[3] ?? 0);
			return new \DateInterval(sprintf('P%dDT%dH%dM%dS', $days, (int)($m[4] ?? 0), (int)($m[5] ?? 0), (int)($m[6] ?? 0)));
		}
		return new \DateInterval('PT0S');
	}

	/** @return list<string> */
	private static function unfold(string $ics): array {
		if (str_starts_with($ics, "\xEF\xBB\xBF")) {
			$ics = substr($ics, 3);
		}
		$ics = (string)preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics));
		return array_values(array_filter(array_map('rtrim', explode("\n", $ics)), fn ($l) => $l !== ''));
	}

	/** @return array{0: string, 1: array<string, string>, 2: string} */
	private static function property(string $line): array {
		// the value starts at the first « : » outside quotes
		$inQuote = false;
		$pos = null;
		for ($i = 0, $n = strlen($line); $i < $n; $i++) {
			if ($line[$i] === '"') {
				$inQuote = !$inQuote;
			} elseif ($line[$i] === ':' && !$inQuote) {
				$pos = $i;
				break;
			}
		}
		$head = $pos === null ? $line : substr($line, 0, $pos);
		$value = $pos === null ? '' : substr($line, $pos + 1);
		$parts = explode(';', $head);
		$name = strtoupper(array_shift($parts));
		$params = [];
		foreach ($parts as $p) {
			[$k, $v] = array_pad(explode('=', $p, 2), 2, '');
			$params[strtoupper($k)] = trim($v, '"');
		}
		return [$name, $params, $value];
	}

	private static function unescape(string $s): string {
		return strtr($s, ['\\n' => "\n", '\\N' => "\n", '\\,' => ',', '\;' => ';', '\\\\' => '\\']);
	}
}
