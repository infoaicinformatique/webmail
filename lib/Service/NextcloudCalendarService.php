<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Exception\MailException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * The Nextcloud calendars of the user (own and shared with them) in the
 * Agenda of the webmail, next to the Exchange ones: read and, where the
 * user may, written — the same events as in the Calendar app of Nextcloud
 * and the phones synced with it. Times from the form are wall clock times
 * in the browser's time zone ($tz), as for Exchange.
 */
class NextcloudCalendarService {
	/** the birthdays of the contacts: made by Nextcloud, not to edit */
	private const BIRTHDAYS = 'contact_birthdays';
	private const READ_ONLY = '{http://owncloud.org/ns}read-only';

	public function available(): bool {
		return class_exists(\OCA\DAV\CalDAV\CalDavBackend::class) && class_exists(Reader::class);
	}

	private function backend(): \OCA\DAV\CalDAV\CalDavBackend {
		if (!$this->available()) {
			throw new MailException('L\'application « dav » de Nextcloud n\'est pas disponible');
		}
		return \OCP\Server::get(\OCA\DAV\CalDAV\CalDavBackend::class);
	}

	/**
	 * The calendars of events of the user, as the webmail lists calendars.
	 * @return list<array<string, mixed>>
	 */
	public function calendars(string $userId): array {
		if (!$this->available()) {
			return [];
		}
		$out = [];
		foreach ($this->backend()->getCalendarsForUser('principals/users/' . $userId) as $c) {
			if (!empty($c['{http://nextcloud.com/ns}deleted-at'])) {
				continue;
			}
			$components = $c['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'] ?? null;
			if (is_object($components) && method_exists($components, 'getValue') && !in_array('VEVENT', (array)$components->getValue(), true)) {
				continue; // a list of tasks
			}
			$owner = (string)($c['{http://owncloud.org/ns}owner-principal'] ?? '');
			$sharedBy = $owner !== '' && $owner !== 'principals/users/' . $userId ? basename($owner) : null;
			$readOnly = !empty($c[self::READ_ONLY]) || $c['uri'] === self::BIRTHDAYS;
			$name = trim((string)($c['{DAV:}displayname'] ?? '')) ?: (string)$c['uri'];
			$out[] = [
				'key' => NcCalendarRules::key((int)$c['id']),
				'name' => $name,
				'kind' => 'nextcloud',
				'canEdit' => !$readOnly,
				'mode' => 'full',
				'color' => NcCalendarRules::color($c['{http://apple.com/ns/ical/}calendar-color'] ?? null),
				'path' => $sharedBy !== null ? (string)($c['{http://nextcloud.com/ns}owner-displayname'] ?? $sharedBy) : null,
				'shared' => $sharedBy !== null,
				// the birthdays: there to show, not shown until asked
				'hiddenByDefault' => $c['uri'] === self::BIRTHDAYS,
			];
		}
		return $out;
	}

	/**
	 * @return array<string, mixed> the calendar (of the list above), checked to be the user's
	 * @throws MailException
	 */
	private function calendar(string $userId, string $key, bool $write = false): array {
		foreach ($this->calendars($userId) as $c) {
			if ($c['key'] === $key) {
				if ($write && !$c['canEdit']) {
					throw new MailException('Cet agenda Nextcloud est en lecture seule pour vous');
				}
				$c['id'] = NcCalendarRules::calendarId($key);
				return $c;
			}
		}
		throw new MailException('Agenda Nextcloud inconnu');
	}

	/** @return list<array<string, mixed>> */
	public function events(string $userId, string $key, \DateTimeImmutable $start, \DateTimeImmutable $end, string $tz): array {
		if ($end <= $start || $end->getTimestamp() - $start->getTimestamp() > 400 * 86400) {
			throw new MailException('Période invalide');
		}
		$cal = $this->calendar($userId, $key);
		$backend = $this->backend();
		$uris = $backend->calendarQuery($cal['id'], [
			'name' => 'VCALENDAR',
			'comp-filters' => [['name' => 'VEVENT', 'comp-filters' => [], 'prop-filters' => [], 'is-not-defined' => false,
				'time-range' => ['start' => \DateTime::createFromImmutable($start), 'end' => \DateTime::createFromImmutable($end)]]],
			'prop-filters' => [],
			'is-not-defined' => false,
			'time-range' => null,
		]);
		$zone = self::zone($tz);
		$events = [];
		foreach (array_chunk($uris, 100) as $chunk) {
			foreach ($backend->getMultipleCalendarObjects($cal['id'], $chunk) as $object) {
				try {
					$data = $object['calendardata'];
					$vcal = Reader::read(is_resource($data) ? (string)stream_get_contents($data) : (string)$data);
					$recurring = self::master($vcal)?->RRULE !== null || self::master($vcal)?->RDATE !== null;
					$overridden = [];
					foreach ($vcal->select('VEVENT') as $e) {
						if ($e->{'RECURRENCE-ID'} !== null) {
							$overridden[self::occurrenceOf($e->{'RECURRENCE-ID'})] = true;
						}
					}
					$expanded = $vcal->expand($start, $end, $zone);
				} catch (\Throwable) {
					continue; // a broken or too repetitive event: left out, as Nextcloud does
				}
				foreach ($expanded->select('VEVENT') as $e) {
					if ($e->DTSTART === null) {
						continue;
					}
					$rid = $recurring && $e->{'RECURRENCE-ID'} !== null ? self::occurrenceOf($e->{'RECURRENCE-ID'}) : null;
					$events[] = $this->summary($e, (string)$object['uri'], $rid, $recurring ? (isset($overridden[$rid]) ? 'Exception' : 'Occurrence') : 'Single', $zone);
				}
			}
		}
		return $events;
	}

	/** @return list<array<string, mixed>> the events matching $query, six months back and ahead */
	public function search(string $userId, string $key, string $query, string $tz): array {
		if (mb_strlen(trim($query)) < 2) {
			throw new MailException('Tapez au moins 2 lettres');
		}
		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$found = array_values(array_filter($this->events($userId, $key, $now->modify('-183 days'), $now->modify('+183 days'), $tz),
			fn ($e) => EwsCalendar::matches($e, $query)));
		usort($found, fn ($a, $b) => strcmp((string)$a['start'], (string)$b['start']));
		return array_slice($found, 0, 200);
	}

	/** @return array<string, mixed> an event with its details (an occurrence: as it is that day) */
	public function event(string $userId, string $key, string $id, string $tz): array {
		$cal = $this->calendar($userId, $key);
		[$uri, $rid] = NcCalendarRules::splitId($id);
		$vcal = $this->load($cal['id'], $uri);
		$zone = self::zone($tz);
		$recurring = self::master($vcal)?->RRULE !== null || self::master($vcal)?->RDATE !== null;
		$e = $rid === null ? self::master($vcal) : $this->instance($vcal, $rid, $zone);
		if ($e === null) {
			throw new MailException('Événement introuvable');
		}
		$overridden = $rid !== null && self::override($vcal, $rid) !== null;
		$event = $this->summary($e, $uri, $rid, $recurring ? ($overridden ? 'Exception' : 'Occurrence') : 'Single', $zone);
		return $event + $this->details($e);
	}

	/** @return array<string, mixed> the series of an occurrence, with its repetition */
	public function series(string $userId, string $key, string $id, string $tz): array {
		$cal = $this->calendar($userId, $key);
		[$uri] = NcCalendarRules::splitId($id);
		$vcal = $this->load($cal['id'], $uri);
		$m = self::master($vcal);
		if ($m === null || $m->RRULE === null) {
			throw new MailException('Série introuvable');
		}
		$zone = self::zone($tz);
		$series = $this->summary($m, $uri, null, 'RecurringMaster', $zone) + $this->details($m);
		$start = self::startOf($m, $zone);
		$series['recurrence'] = NcCalendarRules::recurrence((string)$m->RRULE, $start);
		return $series;
	}

	/** @param array<string, mixed> $data the form */
	public function create(string $userId, string $key, array $data, string $tz): string {
		$cal = $this->calendar($userId, $key, true);
		$d = EwsCalendar::normalize($data);
		$uid = self::uuid();
		$vcal = new VCalendar(['PRODID' => '-//Nextcloud//Webmail Exchange//FR']);
		$e = $vcal->add('VEVENT', ['UID' => $uid]);
		$e->add('CREATED', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
		$this->apply($vcal, $e, $d, $tz, true);
		$uri = $uid . '.ics';
		$this->backend()->createCalendarObject($cal['id'], $uri, $vcal->serialize());
		return $uri;
	}

	/**
	 * @param array<string, mixed> $data the form
	 * @param bool $series $id is an occurrence: the whole series is changed
	 */
	public function update(string $userId, string $key, string $id, array $data, string $tz, bool $series = false): void {
		$cal = $this->calendar($userId, $key, true);
		[$uri, $rid] = NcCalendarRules::splitId($id);
		$vcal = $this->load($cal['id'], $uri);
		$d = EwsCalendar::normalize($data);
		if ($rid === null || $series) {
			$m = self::master($vcal) ?? throw new MailException('Événement introuvable');
			$this->apply($vcal, $m, $d, $tz, $series || $m->RRULE === null);
		} else {
			$d['recurrence'] = null;
			$this->apply($vcal, $this->overrideFor($vcal, $rid, self::zone($tz)), $d, $tz, false);
		}
		$this->backend()->updateCalendarObject($cal['id'], $uri, $vcal->serialize());
	}

	/** Drag and drop: new start and end, the rest kept. */
	public function move(string $userId, string $key, string $id, string $start, string $end, bool $allDay, string $tz): void {
		$cal = $this->calendar($userId, $key, true);
		[$uri, $rid] = NcCalendarRules::splitId($id);
		$vcal = $this->load($cal['id'], $uri);
		$d = EwsCalendar::normalize(['start' => $start, 'end' => $end, 'allDay' => $allDay, 'subject' => 'x']);
		$e = $rid === null ? (self::master($vcal) ?? throw new MailException('Événement introuvable')) : $this->overrideFor($vcal, $rid, self::zone($tz));
		$this->setTimes($e, $d, $tz);
		self::touch($e);
		$this->backend()->updateCalendarObject($cal['id'], $uri, $vcal->serialize());
	}

	/** An event, a series ($series), or one occurrence (left out of its series). */
	public function delete(string $userId, string $key, string $id, bool $series = false): void {
		$cal = $this->calendar($userId, $key, true);
		[$uri, $rid] = NcCalendarRules::splitId($id);
		$backend = $this->backend();
		if ($rid === null || $series) {
			try {
				// into the trash bin of the Calendar app, as from the app
				$backend->deleteCalendarObject($cal['id'], $uri);
			} catch (\Sabre\DAV\Exception\Forbidden) {
				// the trash bin already has a deleted event of this name (« …-deleted.ics »): deleted for good
				$backend->deleteCalendarObject($cal['id'], $uri, \OCA\DAV\CalDAV\CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
			}
			return;
		}
		$vcal = $this->load($cal['id'], $uri);
		$m = self::master($vcal) ?? throw new MailException('Événement introuvable');
		$override = self::override($vcal, $rid);
		if ($override !== null) {
			$vcal->remove($override);
		}
		// EXDATE written as DTSTART is (a date, or a time in its zone)
		$at = self::ridDate($rid);
		if ($m->DTSTART->hasTime()) {
			$tz = $m->DTSTART->getDateTime()->getTimezone();
			$m->add('EXDATE', $at->setTimezone($tz));
		} else {
			$m->add('EXDATE', $at->format('Ymd'), ['VALUE' => 'DATE']);
		}
		self::touch($m);
		$backend->updateCalendarObject($cal['id'], $uri, $vcal->serialize());
	}

	// ------------------------------------------------------------------

	private function load(int $calendarId, string $uri): VCalendar {
		$object = $this->backend()->getCalendarObject($calendarId, $uri);
		if (!is_array($object) || !isset($object['calendardata'])) {
			throw new MailException('Événement introuvable');
		}
		$data = $object['calendardata'];
		$vcal = Reader::read(is_resource($data) ? (string)stream_get_contents($data) : (string)$data);
		if (!$vcal instanceof VCalendar) {
			throw new MailException('Événement illisible');
		}
		return $vcal;
	}

	private static function master(VCalendar $vcal): ?VEvent {
		foreach ($vcal->select('VEVENT') as $e) {
			if ($e->{'RECURRENCE-ID'} === null) {
				return $e;
			}
		}
		return null;
	}

	private static function override(VCalendar $vcal, string $rid): ?VEvent {
		foreach ($vcal->select('VEVENT') as $e) {
			if ($e->{'RECURRENCE-ID'} !== null && self::occurrenceOf($e->{'RECURRENCE-ID'}) === $rid) {
				return $e;
			}
		}
		return null;
	}

	/** One occurrence of a series, as it is (moved, renamed…). */
	private function instance(VCalendar $vcal, string $rid, \DateTimeZone $zone): ?VEvent {
		$at = self::ridDate($rid);
		$expanded = $vcal->expand($at->modify('-2 days'), $at->modify('+2 days'), $zone);
		foreach ($expanded->select('VEVENT') as $e) {
			if ($e->{'RECURRENCE-ID'} !== null && self::occurrenceOf($e->{'RECURRENCE-ID'}) === $rid) {
				return $e;
			}
		}
		// moved far from its day: its own component
		return self::override($vcal, $rid);
	}

	/** The component of one occurrence, made from the series when there is none yet. */
	private function overrideFor(VCalendar $vcal, string $rid, \DateTimeZone $zone): VEvent {
		$existing = self::override($vcal, $rid);
		if ($existing !== null) {
			return $existing;
		}
		$m = self::master($vcal) ?? throw new MailException('Événement introuvable');
		$at = self::ridDate($rid);
		$e = clone $m;
		foreach (['RRULE', 'RDATE', 'EXDATE', 'EXRULE'] as $p) {
			$e->remove($p);
		}
		$duration = self::endOf($m, $zone)->getTimestamp() - self::startOf($m, $zone)->getTimestamp();
		if ($m->DTSTART->hasTime()) {
			$start = $at->setTimezone($m->DTSTART->getDateTime()->getTimezone());
			$e->DTSTART = $start;
			$e->remove('DTEND');
			$e->remove('DURATION');
			$e->add('DTEND', $start->modify('+' . $duration . ' seconds'));
			$e->add('RECURRENCE-ID', $start);
		} else {
			$e->DTSTART = $at->format('Ymd');
			$e->DTSTART['VALUE'] = 'DATE';
			$e->remove('DTEND');
			$e->remove('DURATION');
			$e->add('DTEND', $at->modify('+' . max(1, intdiv($duration, 86400)) . ' days')->format('Ymd'), ['VALUE' => 'DATE']);
			$e->add('RECURRENCE-ID', $at->format('Ymd'), ['VALUE' => 'DATE']);
		}
		$vcal->add($e);
		return $e;
	}

	/**
	 * The form into an event.
	 * @param array<string, mixed> $d EwsCalendar::normalize()
	 * @param bool $withRule the repetition is the form's (a single event or a series)
	 */
	private function apply(VCalendar $vcal, VEvent $e, array $d, string $tz, bool $withRule): void {
		$e->SUMMARY = $d['subject'];
		self::setText($e, 'LOCATION', $d['location']);
		self::setText($e, 'DESCRIPTION', $d['body']);
		$this->setTimes($e, $d, $tz);
		$e->remove('TRANSP');
		$e->add('TRANSP', $d['freeBusy'] === 'Free' ? 'TRANSPARENT' : 'OPAQUE');
		if ($d['freeBusy'] === 'Tentative') {
			$e->STATUS = 'TENTATIVE';
		} elseif ((string)$e->STATUS === 'TENTATIVE') {
			$e->STATUS = 'CONFIRMED';
		}
		foreach ($e->select('VALARM') as $alarm) {
			$e->remove($alarm);
		}
		if ($d['reminder'] >= 0) {
			$e->add('VALARM', [
				'ACTION' => 'DISPLAY',
				'DESCRIPTION' => $d['subject'],
				'TRIGGER' => NcCalendarRules::trigger((int)$d['reminder']),
			]);
		}
		if ($withRule) {
			$rule = $d['recurrence'];
			if ($rule === null) {
				foreach (['RRULE', 'RDATE', 'EXDATE'] as $p) {
					$e->remove($p);
				}
			} elseif (($rule['freq'] ?? '') !== 'keep') {
				$e->RRULE = NcCalendarRules::rrule($rule, new \DateTimeImmutable(substr($d['start'], 0, 10)), $d['allDay']);
			}
		}
		self::touch($e);
	}

	/** @param array<string, mixed> $d */
	private function setTimes(VEvent $e, array $d, string $tz): void {
		$e->remove('DTEND');
		$e->remove('DURATION');
		if ($d['allDay']) {
			$e->DTSTART = str_replace('-', '', substr($d['start'], 0, 10));
			$e->DTSTART['VALUE'] = 'DATE';
			$e->add('DTEND', str_replace('-', '', substr($d['end'], 0, 10)), ['VALUE' => 'DATE']);
		} else {
			$zone = self::zone($tz);
			$e->DTSTART = new \DateTimeImmutable($d['start'], $zone);
			$e->add('DTEND', new \DateTimeImmutable($d['end'], $zone));
		}
	}

	private static function setText(VEvent $e, string $name, string $value): void {
		$e->remove($name);
		if (trim($value) !== '') {
			$e->add($name, $value);
		}
	}

	private static function touch(VEvent $e): void {
		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$e->DTSTAMP = $now;
		$e->{'LAST-MODIFIED'} = $now;
		$e->SEQUENCE = (int)(string)($e->SEQUENCE ?? '0') + 1;
	}

	/** @return array<string, mixed> as EwsCalendar lists events */
	private function summary(VEvent $e, string $uri, ?string $rid, string $type, \DateTimeZone $zone): array {
		$allDay = !$e->DTSTART->hasTime();
		$organizer = $e->ORGANIZER !== null ? self::person($e->ORGANIZER) : null;
		return [
			'id' => NcCalendarRules::eventId($uri, $rid),
			'changeKey' => '',
			'subject' => (string)($e->SUMMARY ?? ''),
			'start' => self::iso(self::startOf($e, $zone)),
			'end' => self::iso(self::endOf($e, $zone)),
			'allDay' => $allDay,
			'location' => (string)($e->LOCATION ?? ''),
			'organizer' => $organizer,
			// the events of Nextcloud are changed here as in the Calendar app: no invitation is sent from the webmail
			'isOrganizer' => true,
			'isMeeting' => false,
			'response' => 'Organizer',
			'isRecurring' => $type !== 'Single',
			'isCancelled' => strtoupper((string)($e->STATUS ?? '')) === 'CANCELLED',
			'freeBusy' => NcCalendarRules::freeBusy((string)($e->TRANSP ?? ''), (string)($e->STATUS ?? '')),
			'type' => $type,
		];
	}

	/** @return array<string, mixed> */
	private function details(VEvent $e): array {
		$required = $optional = $resources = [];
		foreach ($e->select('ATTENDEE') as $a) {
			$p = self::person($a) + ['response' => NcCalendarRules::response((string)($a['PARTSTAT'] ?? ''))];
			$cutype = strtoupper((string)($a['CUTYPE'] ?? ''));
			if ($cutype === 'ROOM' || $cutype === 'RESOURCE') {
				$resources[] = $p;
			} elseif (strtoupper((string)($a['ROLE'] ?? '')) === 'OPT-PARTICIPANT') {
				$optional[] = $p;
			} else {
				$required[] = $p;
			}
		}
		$reminder = -1;
		foreach ($e->select('VALARM') as $alarm) {
			$m = $alarm->TRIGGER !== null ? NcCalendarRules::reminderMinutes((string)$alarm->TRIGGER) : null;
			if ($m !== null) {
				$reminder = $m;
				break;
			}
		}
		return [
			'body' => (string)($e->DESCRIPTION ?? ''),
			'attendees' => $required,
			'optionalAttendees' => $optional,
			'resources' => $resources,
			'reminder' => $reminder,
		];
	}

	/** @return array{name: string, email: string} */
	private static function person(\Sabre\VObject\Property $p): array {
		$email = (string)preg_replace('/^mailto:/i', '', (string)$p->getValue());
		return ['name' => (string)($p['CN'] ?? '') ?: $email, 'email' => $email];
	}

	private static function startOf(VEvent $e, \DateTimeZone $zone): \DateTimeImmutable {
		return $e->DTSTART->hasTime()
			? \DateTimeImmutable::createFromInterface($e->DTSTART->getDateTime())
			: self::day((string)$e->DTSTART->getValue(), $zone);
	}

	/** « 20261114 » at midnight in $zone */
	private static function day(string $value, \DateTimeZone $zone): \DateTimeImmutable {
		$d = \DateTimeImmutable::createFromFormat('!Ymd', substr($value, 0, 8), $zone);
		if ($d === false) {
			throw new MailException('Date invalide : %s', [$value]);
		}
		return $d;
	}

	private static function endOf(VEvent $e, \DateTimeZone $zone): \DateTimeImmutable {
		$start = self::startOf($e, $zone);
		if ($e->DTEND !== null) {
			return $e->DTEND->hasTime()
				? \DateTimeImmutable::createFromInterface($e->DTEND->getDateTime())
				: self::day((string)$e->DTEND->getValue(), $zone);
		}
		if ($e->DURATION !== null) {
			return $start->add($e->DURATION->getDateInterval());
		}
		return $e->DTSTART->hasTime() ? $start : $start->modify('+1 day');
	}

	/** RECURRENCE-ID as in the ids. */
	private static function occurrenceOf(\Sabre\VObject\Property $p): string {
		/** @var \Sabre\VObject\Property\ICalendar\DateTime $p */
		return $p->hasTime()
			? NcCalendarRules::occurrence($p->getDateTime(), false)
			: substr((string)$p->getValue(), 0, 8);
	}

	private static function ridDate(string $rid): \DateTimeImmutable {
		$utc = new \DateTimeZone('UTC');
		$d = strlen($rid) === 8
			? \DateTimeImmutable::createFromFormat('!Ymd', $rid, $utc)
			: \DateTimeImmutable::createFromFormat('!Ymd\\THis', substr($rid, 0, 15), $utc);
		if ($d === false) {
			throw new MailException('Événement introuvable');
		}
		return $d;
	}

	private static function iso(\DateTimeImmutable $d): string {
		return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}

	private static function zone(string $tz): \DateTimeZone {
		try {
			return new \DateTimeZone($tz !== '' ? $tz : 'UTC');
		} catch (\Exception) {
			return new \DateTimeZone('UTC');
		}
	}

	private static function uuid(): string {
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
	}
}
