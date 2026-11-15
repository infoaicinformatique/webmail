<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Exception\MailException;

/**
 * The rules of the Nextcloud calendars shown in the webmail, without
 * Nextcloud: keys and ids, the repetition of the form to and from an RRULE
 * (RFC 5545), reminders, colours. Tested without a server.
 */
final class NcCalendarRules {
	/** the key of a Nextcloud calendar in the webmail (the Exchange ones have theirs) */
	public const PREFIX = 'nc:';

	private const DAYS = ['MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday', 'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday', 'SU' => 'Sunday'];

	public static function key(int $calendarId): string {
		return self::PREFIX . $calendarId;
	}

	public static function isKey(string $key): bool {
		return str_starts_with($key, self::PREFIX);
	}

	/** The id of the calendar of a key, null when it is not one. */
	public static function calendarId(string $key): ?int {
		if (!self::isKey($key) || !ctype_digit(substr($key, strlen(self::PREFIX)))) {
			return null;
		}
		return (int)substr($key, strlen(self::PREFIX));
	}

	/**
	 * The id of an event in the webmail: the object (« abc.ics »), and for
	 * one occurrence of a repetition its RECURRENCE-ID (UTC, or a date).
	 */
	public static function eventId(string $uri, ?string $recurrenceId = null): string {
		return $recurrenceId === null ? $uri : $uri . '|' . $recurrenceId;
	}

	/**
	 * @return array{0: string, 1: ?string} the object, the occurrence
	 * @throws MailException
	 */
	public static function splitId(string $id): array {
		$parts = explode('|', $id, 2);
		$uri = $parts[0];
		if ($uri === '' || str_contains($uri, '/') || str_contains($uri, '..')) {
			throw new MailException('Événement introuvable');
		}
		$rid = $parts[1] ?? null;
		if ($rid !== null && !preg_match('/^\d{8}(T\d{6}Z)?$/', $rid)) {
			throw new MailException('Événement introuvable');
		}
		return [$uri, $rid];
	}

	/** An occurrence as written in an id: « 20261114T080000Z », or « 20261114 » for a whole day. */
	public static function occurrence(\DateTimeInterface $start, bool $allDay): string {
		return $allDay ? $start->format('Ymd') : \DateTimeImmutable::createFromInterface($start)->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
	}

	/** « #0082c9ff » (Nextcloud keeps the opacity) → « #0082c9 »; null when not a colour. */
	public static function color(mixed $value): ?string {
		return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}/', $value) ? strtolower(substr($value, 0, 7)) : null;
	}

	/**
	 * The RRULE of a repetition of the form (EwsCalendar::normalize()).
	 * @param array<string, mixed> $r freq, interval, days (Monday…), by (date | weekday | last), until (Y-m-d), count
	 */
	public static function rrule(array $r, \DateTimeImmutable $start, bool $allDay): string {
		$freq = strtoupper((string)$r['freq']);
		if (!in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
			throw new MailException('Répétition inconnue');
		}
		$parts = ['FREQ=' . $freq];
		$interval = max(1, (int)($r['interval'] ?? 1));
		if ($interval > 1) {
			$parts[] = 'INTERVAL=' . $interval;
		}
		$code = array_search($start->format('l'), self::DAYS, true);
		if ($freq === 'WEEKLY') {
			$days = array_values(array_filter(array_map(fn ($d) => array_search($d, self::DAYS, true), (array)($r['days'] ?? []))));
			$parts[] = 'BYDAY=' . implode(',', $days !== [] ? $days : [$code]);
		} elseif ($freq === 'MONTHLY' || $freq === 'YEARLY') {
			if ($freq === 'YEARLY') {
				$parts[] = 'BYMONTH=' . (int)$start->format('n');
			}
			$by = (string)($r['by'] ?? 'date');
			if ($by === 'date') {
				$parts[] = 'BYMONTHDAY=' . (int)$start->format('j');
			} else {
				$n = intdiv((int)$start->format('j') - 1, 7) + 1;
				$parts[] = 'BYDAY=' . ($by === 'last' || $n > 4 ? '-1' : (string)$n) . $code;
			}
		}
		if (!empty($r['until'])) {
			// the last day included: until its end
			$until = (string)$r['until'];
			$parts[] = 'UNTIL=' . str_replace('-', '', $until) . ($allDay ? '' : 'T235959Z');
		} elseif (!empty($r['count'])) {
			$parts[] = 'COUNT=' . (int)$r['count'];
		}
		return implode(';', $parts);
	}

	/**
	 * A repetition as the form shows it (as EwsCalendar::parseRecurrence()):
	 * what it cannot edit (« the 2nd and 4th Tuesday », hours…) is « other », kept as is.
	 * @return array<string, mixed>
	 */
	public static function recurrence(string $rrule, \DateTimeImmutable $start): array {
		$p = [];
		foreach (explode(';', trim($rrule)) as $part) {
			[$k, $v] = array_pad(explode('=', $part, 2), 2, '');
			if ($k !== '') {
				$p[strtoupper($k)] = strtoupper($v);
			}
		}
		$freq = match ($p['FREQ'] ?? '') {
			'DAILY' => 'daily', 'WEEKLY' => 'weekly', 'MONTHLY' => 'monthly', 'YEARLY' => 'yearly', default => 'other',
		};
		$known = ['FREQ', 'INTERVAL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH', 'UNTIL', 'COUNT', 'WKST'];
		if (array_diff(array_keys($p), $known) !== []) {
			$freq = 'other';
		}
		$days = [];
		$by = 'date';
		$weekIndex = null;
		$byDay = isset($p['BYDAY']) ? explode(',', $p['BYDAY']) : [];
		if ($freq === 'weekly' || $freq === 'daily') {
			foreach ($byDay as $d) {
				if (!isset(self::DAYS[$d])) {
					$freq = 'other';
					break;
				}
				$days[] = self::DAYS[$d];
			}
			if ($freq === 'daily' && $byDay !== []) {
				$freq = 'other'; // « every working day »: not this form's daily
			}
		} elseif ($freq === 'monthly' || $freq === 'yearly') {
			if ($freq === 'yearly' && isset($p['BYMONTH']) && (int)$p['BYMONTH'] !== (int)$start->format('n')) {
				$freq = 'other';
			}
			if (count($byDay) === 1 && preg_match('/^(-1|[1-4])(MO|TU|WE|TH|FR|SA|SU)$/', $byDay[0], $m) && !isset($p['BYMONTHDAY'])) {
				$by = $m[1] === '-1' ? 'last' : 'weekday';
				$weekIndex = $m[1] === '-1' ? 'Last' : ['1' => 'First', '2' => 'Second', '3' => 'Third', '4' => 'Fourth'][$m[1]];
				$days = [self::DAYS[$m[2]]];
			} elseif ($byDay !== [] || (isset($p['BYMONTHDAY']) && (int)$p['BYMONTHDAY'] !== (int)$start->format('j'))) {
				$freq = 'other';
			}
		}
		$until = null;
		if (isset($p['UNTIL']) && preg_match('/^(\d{4})(\d{2})(\d{2})/', $p['UNTIL'], $m)) {
			$until = $m[1] . '-' . $m[2] . '-' . $m[3];
		}
		return [
			'freq' => $freq,
			'pattern' => null,
			'interval' => max(1, (int)($p['INTERVAL'] ?? 1)),
			'days' => $days,
			'dayOfMonth' => ($freq === 'monthly' || $freq === 'yearly') && $by === 'date' ? (int)$start->format('j') : null,
			'by' => $by,
			'weekIndex' => $weekIndex,
			'until' => $until,
			'count' => isset($p['COUNT']) ? (int)$p['COUNT'] : null,
		];
	}

	/** The minutes before the start of a TRIGGER (« -PT15M », « -P1D »…), null when not before the start. */
	public static function reminderMinutes(string $trigger): ?int {
		if (!preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', strtoupper(trim($trigger)), $m) || $trigger === 'P' || $trigger === 'PT') {
			return null;
		}
		$minutes = ((int)($m[2] ?? 0)) * 10080 + ((int)($m[3] ?? 0)) * 1440 + ((int)($m[4] ?? 0)) * 60 + (int)($m[5] ?? 0) + intdiv((int)($m[6] ?? 0), 60);
		if (($m[1] ?? '') !== '-' && $minutes !== 0) {
			return null; // after the start
		}
		return $minutes;
	}

	/** The TRIGGER of a reminder this many minutes before. */
	public static function trigger(int $minutes): string {
		if ($minutes === 0) {
			return 'PT0S';
		}
		if ($minutes % 1440 === 0) {
			return '-P' . intdiv($minutes, 1440) . 'D';
		}
		return $minutes % 60 === 0 ? '-PT' . intdiv($minutes, 60) . 'H' : '-PT' . $minutes . 'M';
	}

	/** « Afficher comme » of an event: TRANSP and STATUS. */
	public static function freeBusy(string $transp, string $status): string {
		if (strtoupper($transp) === 'TRANSPARENT') {
			return 'Free';
		}
		return strtoupper($status) === 'TENTATIVE' ? 'Tentative' : 'Busy';
	}

	/** An answer of an attendee (PARTSTAT) as Exchange names it. */
	public static function response(string $partstat): string {
		return match (strtoupper($partstat)) {
			'ACCEPTED' => 'Accept',
			'TENTATIVE' => 'Tentative',
			'DECLINED' => 'Decline',
			default => 'Unknown',
		};
	}
}
