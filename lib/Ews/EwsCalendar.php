<?php

declare(strict_types=1);

namespace OCA\Webmail\Ews;

use OCA\Webmail\Exception\MailException;

/**
 * Exchange calendar (default "Calendar" folder) through EWS.
 *
 * Dates from the UI are local wall-clock times ("2026-11-20T09:30" or
 * "2026-11-20" for all-day events). They are sent without offset and
 * Exchange interprets them in the time zone set on the client
 * (TimeZoneContext header), which is also stored on new events.
 * Exchange returns UTC times ("...Z").
 */
class EwsCalendar {
	private const LIST_FIELDS = ['item:Subject', 'calendar:Start', 'calendar:End', 'calendar:IsAllDayEvent', 'calendar:Location',
		'calendar:Organizer', 'calendar:MyResponseType', 'calendar:IsRecurring', 'calendar:IsCancelled', 'calendar:IsMeeting',
		'calendar:LegacyFreeBusyStatus', 'calendar:CalendarItemType'];

	private const DETAIL_FIELDS = ['item:Subject', 'item:Body', 'item:ReminderIsSet', 'item:ReminderMinutesBeforeStart',
		'calendar:Start', 'calendar:End', 'calendar:IsAllDayEvent', 'calendar:Location', 'calendar:Organizer',
		'calendar:MyResponseType', 'calendar:IsRecurring', 'calendar:IsCancelled', 'calendar:IsMeeting',
		'calendar:LegacyFreeBusyStatus', 'calendar:CalendarItemType', 'calendar:RequiredAttendees', 'calendar:OptionalAttendees'];

	public function __construct(
		private EwsClient $client,
	) {
	}

	/**
	 * Events (recurrences expanded) overlapping [$start, $end[.
	 * @return array<int, array<string, mixed>>
	 */
	public function findEvents(\DateTimeInterface $start, \DateTimeInterface $end): array {
		if ($end <= $start) {
			throw new MailException('Période invalide');
		}
		if ($end->getTimestamp() - $start->getTimestamp() > 400 * 86400) {
			throw new MailException('Période trop longue (maximum un an)');
		}
		$xp = $this->client->request(
			'<m:FindItem Traversal="Shallow">'
			. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . self::fields(self::LIST_FIELDS) . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:CalendarView MaxEntriesReturned="1000" StartDate="' . self::utc($start) . '" EndDate="' . self::utc($end) . '"/>'
			. '<m:ParentFolderIds><t:DistinguishedFolderId Id="calendar"/></m:ParentFolderIds>'
			. '</m:FindItem>'
		);
		$events = [];
		foreach ($xp->query('//m:RootFolder/t:Items/t:CalendarItem') as $node) {
			$events[] = $this->parseEvent($xp, $node, false);
		}
		return $events;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getEvent(string $id): array {
		$xp = $this->client->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:BodyType>Text</t:BodyType>'
			. '<t:AdditionalProperties>' . self::fields(self::DETAIL_FIELDS) . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds><t:ItemId Id="' . $this->client->esc($id) . '"/></m:ItemIds></m:GetItem>'
		);
		$node = $xp->query('//m:Items/t:CalendarItem')->item(0);
		if ($node === null) {
			throw new MailException('Événement introuvable');
		}
		return $this->parseEvent($xp, $node, true);
	}

	/**
	 * @param array<string, mixed> $data see normalize()
	 * @return string id of the new event
	 */
	public function createEvent(array $data): string {
		$e = self::normalize($data);
		$tz = $this->client->getTimeZone() ?? 'UTC';
		$hasAttendees = $e['attendees'] !== [] || $e['optionalAttendees'] !== [];

		$item = '<t:CalendarItem>'
			. '<t:Subject>' . $this->client->esc($e['subject']) . '</t:Subject>'
			. '<t:Body BodyType="Text">' . $this->client->esc($e['body']) . '</t:Body>'
			. '<t:ReminderIsSet>' . ($e['reminder'] >= 0 ? 'true' : 'false') . '</t:ReminderIsSet>'
			. ($e['reminder'] >= 0 ? '<t:ReminderMinutesBeforeStart>' . $e['reminder'] . '</t:ReminderMinutesBeforeStart>' : '')
			. '<t:Start>' . $e['start'] . '</t:Start>'
			. '<t:End>' . $e['end'] . '</t:End>'
			. '<t:IsAllDayEvent>' . ($e['allDay'] ? 'true' : 'false') . '</t:IsAllDayEvent>'
			. '<t:LegacyFreeBusyStatus>' . $e['freeBusy'] . '</t:LegacyFreeBusyStatus>'
			. ($e['location'] !== '' ? '<t:Location>' . $this->client->esc($e['location']) . '</t:Location>' : '')
			. $this->attendeesXml('RequiredAttendees', $e['attendees'])
			. $this->attendeesXml('OptionalAttendees', $e['optionalAttendees'])
			. $this->recurrenceXml($e)
			. '<t:StartTimeZone Id="' . $this->client->esc($tz) . '"/>'
			. '<t:EndTimeZone Id="' . $this->client->esc($tz) . '"/>'
			. '</t:CalendarItem>';

		$xp = $this->client->request(
			'<m:CreateItem SendMeetingInvitations="' . ($hasAttendees ? 'SendToAllAndSaveCopy' : 'SendToNone') . '">'
			. '<m:SavedItemFolderId><t:DistinguishedFolderId Id="calendar"/></m:SavedItemFolderId>'
			. '<m:Items>' . $item . '</m:Items></m:CreateItem>'
		);
		return (string)$this->client->attr($xp, '//m:Items/t:CalendarItem/t:ItemId', 'Id');
	}

	/**
	 * The recurring series an occurrence belongs to (its "master"), with its
	 * recurrence rule. Start and end are those of the first occurrence.
	 * @return array<string, mixed>
	 */
	public function getSeries(string $occurrenceId): array {
		$xp = $this->client->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:BodyType>Text</t:BodyType>'
			. '<t:AdditionalProperties>' . self::fields(array_merge(self::DETAIL_FIELDS, ['calendar:Recurrence'])) . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds><t:RecurringMasterItemId OccurrenceId="' . $this->client->esc($occurrenceId) . '"/></m:ItemIds></m:GetItem>'
		);
		$node = $xp->query('//m:Items/t:CalendarItem')->item(0);
		if ($node === null) {
			throw new MailException('Série introuvable');
		}
		$series = $this->parseEvent($xp, $node, true);
		$series['recurrence'] = self::parseRecurrence($xp, $node);
		return $series;
	}

	/**
	 * Read the t:Recurrence element of a recurring master.
	 * freq is daily | weekly | monthly | yearly, or "other" for patterns this
	 * UI cannot edit (e.g. "the 2nd Tuesday of the month"): those are kept as is.
	 * @return ?array<string, mixed>
	 */
	public static function parseRecurrence(\DOMXPath $xp, \DOMNode $node): ?array {
		$rec = $xp->query('t:Recurrence', $node)->item(0);
		if ($rec === null) {
			return null;
		}
		$pattern = null;
		$range = null;
		foreach ($rec->childNodes as $child) {
			if (!$child instanceof \DOMElement) {
				continue;
			}
			if (str_ends_with($child->localName, 'Recurrence') && in_array($child->localName, ['NoEndRecurrence', 'EndDateRecurrence', 'NumberedRecurrence'], true)) {
				$range = $child;
			} elseif ($pattern === null) {
				$pattern = $child;
			}
		}
		$value = fn (string $name, ?\DOMNode $ctx) => $ctx === null ? null : ($xp->query('t:' . $name, $ctx)->item(0)?->textContent);

		$freq = match ($pattern?->localName) {
			'DailyRecurrence' => 'daily',
			'WeeklyRecurrence' => 'weekly',
			'AbsoluteMonthlyRecurrence' => 'monthly',
			'AbsoluteYearlyRecurrence' => 'yearly',
			default => 'other',
		};
		$days = trim((string)$value('DaysOfWeek', $pattern));
		$endDate = $value('EndDate', $range);
		$count = $value('NumberOfOccurrences', $range);
		return [
			'freq' => $freq,
			'pattern' => $pattern?->localName,
			'interval' => max(1, (int)($value('Interval', $pattern) ?? 1)),
			'days' => $days === '' ? [] : preg_split('/\s+/', $days),
			'dayOfMonth' => $value('DayOfMonth', $pattern) !== null ? (int)$value('DayOfMonth', $pattern) : null,
			'until' => $endDate !== null ? substr($endDate, 0, 10) : null,
			'count' => $count !== null ? (int)$count : null,
		];
	}

	/**
	 * Update an event, an occurrence, or — with $series = true and the id of
	 * the series master (see getSeries) — the whole recurring series,
	 * including its recurrence rule.
	 * @param array<string, mixed> $data
	 */
	public function updateEvent(string $id, array $data, bool $series = false): void {
		$e = self::normalize($data);
		$c = $this->client;
		$set = fn (string $field, string $inner) => '<t:SetItemField><t:FieldURI FieldURI="' . $field . '"/><t:CalendarItem>' . $inner . '</t:CalendarItem></t:SetItemField>';
		$del = fn (string $field) => '<t:DeleteItemField><t:FieldURI FieldURI="' . $field . '"/></t:DeleteItemField>';

		$updates = $set('item:Subject', '<t:Subject>' . $c->esc($e['subject']) . '</t:Subject>')
			. $set('item:Body', '<t:Body BodyType="Text">' . $c->esc($e['body']) . '</t:Body>')
			. $set('item:ReminderIsSet', '<t:ReminderIsSet>' . ($e['reminder'] >= 0 ? 'true' : 'false') . '</t:ReminderIsSet>')
			. ($e['reminder'] >= 0 ? $set('item:ReminderMinutesBeforeStart', '<t:ReminderMinutesBeforeStart>' . $e['reminder'] . '</t:ReminderMinutesBeforeStart>') : '')
			. $set('calendar:Start', '<t:Start>' . $e['start'] . '</t:Start>')
			. $set('calendar:End', '<t:End>' . $e['end'] . '</t:End>')
			. $set('calendar:IsAllDayEvent', '<t:IsAllDayEvent>' . ($e['allDay'] ? 'true' : 'false') . '</t:IsAllDayEvent>')
			. $set('calendar:LegacyFreeBusyStatus', '<t:LegacyFreeBusyStatus>' . $e['freeBusy'] . '</t:LegacyFreeBusyStatus>')
			. ($e['location'] !== '' ? $set('calendar:Location', '<t:Location>' . $c->esc($e['location']) . '</t:Location>') : $del('calendar:Location'))
			. ($e['attendees'] !== [] ? $set('calendar:RequiredAttendees', $this->attendeesXml('RequiredAttendees', $e['attendees'])) : $del('calendar:RequiredAttendees'))
			. ($e['optionalAttendees'] !== [] ? $set('calendar:OptionalAttendees', $this->attendeesXml('OptionalAttendees', $e['optionalAttendees'])) : $del('calendar:OptionalAttendees'));

		if ($series) {
			$rec = $e['recurrence'];
			if ($rec === null) {
				// "does not repeat": the series becomes a single event
				$updates .= $del('calendar:Recurrence');
			} elseif ($rec['freq'] !== 'keep') {
				$updates .= $set('calendar:Recurrence', $this->recurrenceXml($e));
			}
		}

		$notify = ($e['attendees'] !== [] || $e['optionalAttendees'] !== []) ? 'SendToAllAndSaveCopy' : 'SendToNone';
		$this->client->request(
			'<m:UpdateItem ConflictResolution="AlwaysOverwrite" SendMeetingInvitationsOrCancellations="' . $notify . '">'
			. '<m:ItemChanges><t:ItemChange><t:ItemId Id="' . $c->esc($id) . '"/><t:Updates>' . $updates . '</t:Updates></t:ItemChange></m:ItemChanges>'
			. '</m:UpdateItem>'
		);
	}

	/**
	 * Delete an event. For an occurrence, $series = true deletes the whole series.
	 * Attendees of a meeting you organise receive a cancellation.
	 */
	public function deleteEvent(string $id, bool $series = false): void {
		$ids = $series
			? '<t:RecurringMasterItemId OccurrenceId="' . $this->client->esc($id) . '"/>'
			: '<t:ItemId Id="' . $this->client->esc($id) . '"/>';
		$this->client->request(
			'<m:DeleteItem DeleteType="MoveToDeletedItems" SendMeetingCancellations="SendToAllAndSaveCopy">'
			. '<m:ItemIds>' . $ids . '</m:ItemIds></m:DeleteItem>'
		);
	}

	/**
	 * Answer a meeting invitation. $id may be the calendar item or the
	 * meeting request received by e-mail.
	 */
	public function respond(string $id, string $response, string $message = ''): void {
		$element = match ($response) {
			'accept' => 'AcceptItem',
			'tentative' => 'TentativelyAcceptItem',
			'decline' => 'DeclineItem',
			default => throw new MailException('Réponse inconnue'),
		};
		$ref = $this->client->itemRef($id);
		$body = trim($message) !== '' ? '<t:Body BodyType="Text">' . $this->client->esc($message) . '</t:Body>' : '';
		$this->client->request(
			'<m:CreateItem MessageDisposition="SendAndSaveCopy"><m:Items>'
			. '<t:' . $element . '>' . $body
			. '<t:ReferenceItemId Id="' . $this->client->esc($ref['id']) . '" ChangeKey="' . $this->client->esc($ref['changeKey']) . '"/>'
			. '</t:' . $element . '></m:Items></m:CreateItem>'
		);
	}

	// ------------------------------------------------------------------

	/**
	 * Validate and normalise event data coming from the UI.
	 *
	 * @param array<string, mixed> $data subject, start, end, allDay, location, body, attendees[], optionalAttendees[],
	 *                                   reminder (minutes, -1 = none), freeBusy,
	 *                                   recurrence {freq, interval, days[], until, count} (see normalizeRecurrence)
	 * @return array<string, mixed>
	 */
	public static function normalize(array $data): array {
		$allDay = !empty($data['allDay']);
		$subject = trim((string)($data['subject'] ?? ''));
		if ($subject === '') {
			$subject = '(sans titre)';
		}

		if ($allDay) {
			$startDate = self::parseDate((string)($data['start'] ?? ''));
			$endDate = self::parseDate((string)($data['end'] ?? '') ?: (string)$data['start']);
			if ($endDate < $startDate) {
				throw new MailException('La fin de l\'événement est avant son début');
			}
			// the UI gives an inclusive last day, Exchange wants the next midnight
			$start = $startDate->format('Y-m-d') . 'T00:00:00';
			$end = $endDate->modify('+1 day')->format('Y-m-d') . 'T00:00:00';
		} else {
			$s = self::parseDateTime((string)($data['start'] ?? ''));
			$en = self::parseDateTime((string)($data['end'] ?? ''));
			if ($en <= $s) {
				throw new MailException('La fin de l\'événement doit être après son début');
			}
			$start = $s->format('Y-m-d\TH:i:s');
			$end = $en->format('Y-m-d\TH:i:s');
		}

		$freeBusy = (string)($data['freeBusy'] ?? 'Busy');
		if (!in_array($freeBusy, ['Free', 'Tentative', 'Busy', 'OOF', 'WorkingElsewhere'], true)) {
			$freeBusy = 'Busy';
		}
		$reminder = isset($data['reminder']) && $data['reminder'] !== '' ? (int)$data['reminder'] : 15;
		$reminder = max(-1, min(40320, $reminder));

		$recurrence = self::normalizeRecurrence($data['recurrence'] ?? null, substr($start, 0, 10));

		return [
			'subject' => $subject,
			'start' => $start,
			'end' => $end,
			'allDay' => $allDay,
			'location' => trim((string)($data['location'] ?? '')),
			'body' => (string)($data['body'] ?? ''),
			'attendees' => self::emails($data['attendees'] ?? []),
			'optionalAttendees' => self::emails($data['optionalAttendees'] ?? []),
			'reminder' => $reminder,
			'freeBusy' => $freeBusy,
			'recurrence' => $recurrence,
		];
	}

	private const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

	/**
	 * @param mixed $r {freq: daily|weekly|monthly|yearly|keep|none, interval, days[], until (Y-m-d), count}
	 * @return ?array<string, mixed> null = no recurrence
	 */
	private static function normalizeRecurrence($r, string $startDate): ?array {
		if (!is_array($r) || empty($r['freq']) || $r['freq'] === 'none') {
			return null;
		}
		$freq = (string)$r['freq'];
		if ($freq === 'keep') {
			return ['freq' => 'keep'];
		}
		if (!in_array($freq, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
			throw new MailException('Répétition inconnue');
		}
		$interval = isset($r['interval']) && $r['interval'] !== '' ? (int)$r['interval'] : 1;
		if ($interval < 1 || $interval > 99) {
			throw new MailException('L\'intervalle de répétition doit être compris entre 1 et 99');
		}
		$days = [];
		if ($freq === 'weekly') {
			foreach ((array)($r['days'] ?? []) as $d) {
				if (!in_array($d, self::WEEKDAYS, true)) {
					throw new MailException('Jour de la semaine inconnu : ' . $d);
				}
				$days[] = $d;
			}
			if ($days === []) {
				$days[] = (new \DateTimeImmutable($startDate))->format('l');
			}
			// Exchange expects the days in week order
			$days = array_values(array_intersect(self::WEEKDAYS, $days));
		}
		$until = !empty($r['until']) ? self::parseDate((string)$r['until'])->format('Y-m-d') : null;
		if ($until !== null && $until < $startDate) {
			throw new MailException('La fin de la répétition est avant le début de l\'événement');
		}
		$count = !empty($r['count']) ? (int)$r['count'] : null;
		if ($count !== null && ($count < 1 || $count > 999)) {
			throw new MailException('Le nombre d\'occurrences doit être compris entre 1 et 999');
		}
		return ['freq' => $freq, 'interval' => $interval, 'days' => $days, 'until' => $until, 'count' => $until === null ? $count : null];
	}

	/**
	 * @param mixed $list
	 * @return string[]
	 */
	private static function emails($list): array {
		if (is_string($list)) {
			$list = preg_split('/[,;\n]+/', $list) ?: [];
		}
		$out = [];
		foreach ((array)$list as $addr) {
			$addr = trim((string)$addr);
			if (preg_match('/<([^>]+)>/', $addr, $m)) {
				$addr = trim($m[1]);
			}
			if ($addr === '') {
				continue;
			}
			if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
				throw new MailException('Adresse de participant invalide : ' . $addr);
			}
			$out[] = $addr;
		}
		return array_values(array_unique($out));
	}

	private static function parseDate(string $value): \DateTimeImmutable {
		$d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($value), 0, 10));
		if ($d === false) {
			throw new MailException('Date invalide : ' . $value);
		}
		return $d;
	}

	private static function parseDateTime(string $value): \DateTimeImmutable {
		$value = trim($value);
		foreach (['!Y-m-d\TH:i', '!Y-m-d\TH:i:s', '!Y-m-d H:i'] as $format) {
			$d = \DateTimeImmutable::createFromFormat($format, $value);
			if ($d !== false) {
				return $d;
			}
		}
		throw new MailException('Date et heure invalides : ' . $value);
	}

	private static function utc(\DateTimeInterface $d): string {
		return (new \DateTimeImmutable('@' . $d->getTimestamp()))->format('Y-m-d\TH:i:s\Z');
	}

	/** @param string[] $fields */
	private static function fields(array $fields): string {
		$xml = '';
		foreach ($fields as $f) {
			$xml .= '<t:FieldURI FieldURI="' . $f . '"/>';
		}
		return $xml;
	}

	/** @param string[] $emails */
	private function attendeesXml(string $element, array $emails): string {
		if ($emails === []) {
			return '';
		}
		$xml = '';
		foreach ($emails as $e) {
			$xml .= '<t:Attendee><t:Mailbox><t:EmailAddress>' . $this->client->esc($e) . '</t:EmailAddress></t:Mailbox></t:Attendee>';
		}
		return '<t:' . $element . '>' . $xml . '</t:' . $element . '>';
	}

	/** @param array<string, mixed> $e */
	private function recurrenceXml(array $e): string {
		$r = $e['recurrence'];
		if ($r === null || $r['freq'] === 'keep') {
			return '';
		}
		$start = new \DateTimeImmutable(substr($e['start'], 0, 10));
		$interval = '<t:Interval>' . $r['interval'] . '</t:Interval>';
		$pattern = match ($r['freq']) {
			'daily' => '<t:DailyRecurrence>' . $interval . '</t:DailyRecurrence>',
			'weekly' => '<t:WeeklyRecurrence>' . $interval . '<t:DaysOfWeek>' . implode(' ', $r['days']) . '</t:DaysOfWeek></t:WeeklyRecurrence>',
			'monthly' => '<t:AbsoluteMonthlyRecurrence>' . $interval . '<t:DayOfMonth>' . $start->format('j') . '</t:DayOfMonth></t:AbsoluteMonthlyRecurrence>',
			'yearly' => '<t:AbsoluteYearlyRecurrence><t:DayOfMonth>' . $start->format('j') . '</t:DayOfMonth><t:Month>' . $start->format('F') . '</t:Month></t:AbsoluteYearlyRecurrence>',
		};
		$startDate = '<t:StartDate>' . $start->format('Y-m-d') . '</t:StartDate>';
		$range = match (true) {
			$r['until'] !== null => '<t:EndDateRecurrence>' . $startDate . '<t:EndDate>' . $r['until'] . '</t:EndDate></t:EndDateRecurrence>',
			$r['count'] !== null => '<t:NumberedRecurrence>' . $startDate . '<t:NumberOfOccurrences>' . $r['count'] . '</t:NumberOfOccurrences></t:NumberedRecurrence>',
			default => '<t:NoEndRecurrence>' . $startDate . '</t:NoEndRecurrence>',
		};
		return '<t:Recurrence>' . $pattern . $range . '</t:Recurrence>';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parseEvent(\DOMXPath $xp, \DOMNode $node, bool $detail): array {
		$c = $this->client;
		$organizer = $c->mailbox($xp, 't:Organizer/t:Mailbox', $node);
		$response = $c->text($xp, 't:MyResponseType', $node) ?? 'Unknown';
		$event = [
			'id' => $c->attr($xp, 't:ItemId', 'Id', $node),
			'changeKey' => $c->attr($xp, 't:ItemId', 'ChangeKey', $node),
			'subject' => (string)$c->text($xp, 't:Subject', $node),
			'start' => $c->text($xp, 't:Start', $node),
			'end' => $c->text($xp, 't:End', $node),
			'allDay' => $c->text($xp, 't:IsAllDayEvent', $node) === 'true',
			'location' => (string)$c->text($xp, 't:Location', $node),
			'organizer' => $organizer,
			'isOrganizer' => $response === 'Organizer' || $c->text($xp, 't:IsMeeting', $node) !== 'true',
			'isMeeting' => $c->text($xp, 't:IsMeeting', $node) === 'true',
			'response' => $response,
			'isRecurring' => $c->text($xp, 't:IsRecurring', $node) === 'true',
			'isCancelled' => $c->text($xp, 't:IsCancelled', $node) === 'true',
			'freeBusy' => $c->text($xp, 't:LegacyFreeBusyStatus', $node) ?? 'Busy',
			'type' => $c->text($xp, 't:CalendarItemType', $node) ?? 'Single',
		];
		if ($detail) {
			$attendees = function (string $path) use ($xp, $node, $c): array {
				$out = [];
				foreach ($xp->query($path . '/t:Attendee', $node) as $a) {
					$mb = $c->mailbox($xp, 't:Mailbox', $a) ?? ['name' => '', 'email' => ''];
					$mb['response'] = $c->text($xp, 't:ResponseType', $a) ?? 'Unknown';
					$out[] = $mb;
				}
				return $out;
			};
			$event['body'] = (string)$c->text($xp, 't:Body', $node);
			$event['attendees'] = $attendees('t:RequiredAttendees');
			$event['optionalAttendees'] = $attendees('t:OptionalAttendees');
			$event['reminder'] = $c->text($xp, 't:ReminderIsSet', $node) === 'true'
				? (int)$c->text($xp, 't:ReminderMinutesBeforeStart', $node) : -1;
		}
		return $event;
	}
}
