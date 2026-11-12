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

	// ------------------------------------------------------------------
	// Calendars: the mailbox's own calendars, and other people's calendars
	// ------------------------------------------------------------------

	/**
	 * Folder reference for a calendar key:
	 *  - "calendar"        the default calendar of the mailbox
	 *  - "shared:<email>"  the default calendar of another mailbox (shared with us)
	 *  - anything else     a FolderId of the mailbox (secondary calendar)
	 */
	public function folderXml(string $calendar): string {
		if ($calendar === '' || $calendar === 'calendar') {
			return '<t:DistinguishedFolderId Id="calendar"/>';
		}
		if (str_starts_with($calendar, 'public:')) {
			// a calendar of the public folders (the client then talks to the public folder mailbox)
			return '<t:FolderId Id="' . $this->client->esc(substr($calendar, 7)) . '"/>';
		}
		if (str_starts_with($calendar, 'shared:')) {
			$email = substr($calendar, 7);
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				throw new MailException('Agenda partagé invalide');
			}
			return '<t:DistinguishedFolderId Id="calendar"><t:Mailbox><t:EmailAddress>' . $this->client->esc($email)
				. '</t:EmailAddress></t:Mailbox></t:DistinguishedFolderId>';
		}
		return '<t:FolderId Id="' . $this->client->esc($calendar) . '"/>';
	}

	/**
	 * Calendars of the mailbox: the default one first, then secondary ones.
	 * @return array<int, array{key: string, name: string, kind: string, canEdit: bool, mode: string}>
	 */
	public function listOwnCalendars(): array {
		$defaultId = $this->client->distinguishedFolderIds(['calendar'])['calendar'] ?? null;
		$xp = $this->client->request(
			'<m:FindFolder Traversal="Deep">'
			. '<m:FolderShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
			. '<t:FieldURI FieldURI="folder:DisplayName"/><t:FieldURI FieldURI="folder:FolderClass"/>'
			. '</t:AdditionalProperties></m:FolderShape>'
			. '<m:ParentFolderIds><t:DistinguishedFolderId Id="msgfolderroot"/></m:ParentFolderIds>'
			. '</m:FindFolder>'
		);
		$default = ['key' => 'calendar', 'name' => 'Calendrier', 'kind' => 'own', 'canEdit' => true, 'mode' => 'full'];
		$others = [];
		foreach ($xp->query('//t:Folders/*') as $node) {
			$class = (string)$this->client->text($xp, 't:FolderClass', $node);
			if (!str_starts_with($class, 'IPF.Appointment')) {
				continue;
			}
			$id = (string)$this->client->attr($xp, 't:FolderId', 'Id', $node);
			$name = (string)$this->client->text($xp, 't:DisplayName', $node);
			if ($id === $defaultId) {
				$default['name'] = $name !== '' ? $name : $default['name'];
				continue;
			}
			$others[] = ['key' => $id, 'name' => $name, 'kind' => 'own', 'canEdit' => true, 'mode' => 'full'];
		}
		usort($others, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		return array_merge([$default], $others);
	}

	/**
	 * What we may do with another person's calendar:
	 *  - mode "full": items are readable (canEdit if we may create items)
	 *  - mode "freebusy": only availability can be read (Exchange default)
	 * @return array{mode: string, canEdit: bool}
	 * @throws MailException when the mailbox does not exist or shares nothing
	 */
	public function probeSharedCalendar(string $email): array {
		$xp = $this->client->request(
			'<m:GetFolder><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
			. '<t:FieldURI FieldURI="folder:EffectiveRights"/></t:AdditionalProperties></m:FolderShape>'
			. '<m:FolderIds>' . $this->folderXml('shared:' . $email) . '</m:FolderIds></m:GetFolder>',
			false
		);
		$msg = $xp->query('//m:ResponseMessages/*')->item(0);
		if ($msg instanceof \DOMElement && $msg->getAttribute('ResponseClass') !== 'Error') {
			$canEdit = $this->client->text($xp, './/t:EffectiveRights/t:CreateContents', $msg) === 'true';
			$canRead = $this->client->text($xp, './/t:EffectiveRights/t:Read', $msg) !== 'false';
			if ($canRead) {
				return ['mode' => 'full', 'canEdit' => $canEdit];
			}
		} else {
			$code = $msg !== null ? $this->client->text($xp, 'm:ResponseCode', $msg) : null;
			if ($code === 'ErrorNonExistentMailbox' || $code === 'ErrorInvalidSmtpAddress') {
				throw new MailException('Aucune boîte Exchange ne correspond à ' . $email);
			}
		}
		// no access to the items: availability is usually still visible
		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$this->freeBusy($email, $now, $now->modify('+1 day'));
		return ['mode' => 'freebusy', 'canEdit' => false];
	}

	/**
	 * Availability of another mailbox (GetUserAvailability): busy blocks,
	 * with subject and location when the owner shares them.
	 * Times are requested and returned in UTC.
	 * @return array<int, array<string, mixed>>
	 */
	public function freeBusy(string $email, \DateTimeInterface $start, \DateTimeInterface $end): array {
		if ($end->getTimestamp() - $start->getTimestamp() > 62 * 86400) {
			throw new MailException('Période trop longue pour les disponibilités (maximum 62 jours)');
		}
		$utc = '<t:Bias>0</t:Bias><t:Time>00:00:00</t:Time><t:DayOrder>1</t:DayOrder>';
		$xp = $this->client->request(
			'<m:GetUserAvailabilityRequest>'
			. '<t:TimeZone><t:Bias>0</t:Bias>'
			. '<t:StandardTime>' . $utc . '<t:Month>1</t:Month><t:DayOfWeek>Sunday</t:DayOfWeek></t:StandardTime>'
			. '<t:DaylightTime>' . $utc . '<t:Month>1</t:Month><t:DayOfWeek>Sunday</t:DayOfWeek></t:DaylightTime>'
			. '</t:TimeZone>'
			. '<m:MailboxDataArray><t:MailboxData><t:Email><t:Address>' . $this->client->esc($email) . '</t:Address></t:Email>'
			. '<t:AttendeeType>Required</t:AttendeeType></t:MailboxData></m:MailboxDataArray>'
			. '<t:FreeBusyViewOptions><t:TimeWindow><t:StartTime>' . self::utcNoZone($start) . '</t:StartTime>'
			. '<t:EndTime>' . self::utcNoZone($end) . '</t:EndTime></t:TimeWindow>'
			. '<t:RequestedView>Detailed</t:RequestedView></t:FreeBusyViewOptions>'
			. '</m:GetUserAvailabilityRequest>',
			false
		);
		$msg = $xp->query('//m:FreeBusyResponse/m:ResponseMessage')->item(0);
		if (!$msg instanceof \DOMElement || $msg->getAttribute('ResponseClass') === 'Error') {
			$code = $msg !== null ? $this->client->text($xp, 'm:ResponseCode', $msg) : null;
			throw new MailException(match ($code) {
				'ErrorMailRecipientNotFound', 'ErrorNonExistentMailbox' => 'Aucune boîte Exchange ne correspond à ' . $email,
				default => 'Cet agenda n\'est pas partagé avec vous (pas même les disponibilités)',
			});
		}
		$labels = ['Busy' => 'Occupé', 'Tentative' => 'Provisoire', 'OOF' => 'Absent', 'WorkingElsewhere' => 'Ailleurs'];
		$events = [];
		foreach ($xp->query('//m:FreeBusyView/t:CalendarEventArray/t:CalendarEvent') as $i => $node) {
			$busy = (string)$this->client->text($xp, 't:BusyType', $node);
			if ($busy === 'Free' || $busy === 'NoData') {
				continue;
			}
			$subject = $this->client->text($xp, 't:CalendarEventDetails/t:Subject', $node);
			$events[] = [
				'id' => 'fb:' . $email . ':' . $i,
				'subject' => $subject !== null && $subject !== '' ? $subject : ($labels[$busy] ?? 'Occupé'),
				'start' => substr((string)$this->client->text($xp, 't:StartTime', $node), 0, 19) . 'Z',
				'end' => substr((string)$this->client->text($xp, 't:EndTime', $node), 0, 19) . 'Z',
				'allDay' => false,
				'location' => (string)$this->client->text($xp, 't:CalendarEventDetails/t:Location', $node),
				'freeBusy' => $busy,
				'freeBusyOnly' => true,
				'isOrganizer' => false,
				'isMeeting' => $this->client->text($xp, 't:CalendarEventDetails/t:IsMeeting', $node) === 'true',
				'isRecurring' => $this->client->text($xp, 't:CalendarEventDetails/t:IsRecurring', $node) === 'true',
				'isCancelled' => false,
				'response' => 'Unknown',
				'type' => 'Single',
				'organizer' => null,
			];
		}
		return $events;
	}

	/**
	 * Availability of several people at once (the scheduling assistant):
	 * for each address, its busy blocks, or an error (unknown address,
	 * someone outside the organisation…). Times in UTC.
	 * @param string[] $emails at most 20
	 * @return list<array{email: string, busy: list<array{start: string, end: string, type: string}>, error: ?string}>
	 */
	public function availability(array $emails, \DateTimeInterface $start, \DateTimeInterface $end): array {
		$emails = array_values(array_unique(array_filter(array_map(fn ($e) => strtolower(trim((string)$e)), $emails), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false)));
		if ($emails === []) {
			return [];
		}
		if (count($emails) > 20) {
			throw new MailException('Trop de participants pour afficher leurs disponibilités (20 au plus)');
		}
		if ($end <= $start || $end->getTimestamp() - $start->getTimestamp() > 14 * 86400) {
			throw new MailException('Période de disponibilités invalide (14 jours au plus)');
		}
		$utc = '<t:Bias>0</t:Bias><t:Time>00:00:00</t:Time><t:DayOrder>1</t:DayOrder>';
		$xp = $this->client->request(
			'<m:GetUserAvailabilityRequest>'
			. '<t:TimeZone><t:Bias>0</t:Bias>'
			. '<t:StandardTime>' . $utc . '<t:Month>1</t:Month><t:DayOfWeek>Sunday</t:DayOfWeek></t:StandardTime>'
			. '<t:DaylightTime>' . $utc . '<t:Month>1</t:Month><t:DayOfWeek>Sunday</t:DayOfWeek></t:DaylightTime>'
			. '</t:TimeZone>'
			. '<m:MailboxDataArray>'
			. implode('', array_map(fn ($e) => '<t:MailboxData><t:Email><t:Address>' . $this->client->esc($e) . '</t:Address></t:Email>'
				. '<t:AttendeeType>Required</t:AttendeeType></t:MailboxData>', $emails))
			. '</m:MailboxDataArray>'
			. '<t:FreeBusyViewOptions><t:TimeWindow><t:StartTime>' . self::utcNoZone($start) . '</t:StartTime>'
			. '<t:EndTime>' . self::utcNoZone($end) . '</t:EndTime></t:TimeWindow>'
			. '<t:RequestedView>FreeBusy</t:RequestedView></t:FreeBusyViewOptions>'
			. '</m:GetUserAvailabilityRequest>',
			false
		);
		$out = [];
		// one FreeBusyResponse per address, in the order asked
		foreach ($xp->query('//m:FreeBusyResponseArray/m:FreeBusyResponse') as $i => $resp) {
			if (!isset($emails[$i])) {
				break;
			}
			$msg = $xp->query('m:ResponseMessage', $resp)->item(0);
			if ($msg instanceof \DOMElement && $msg->getAttribute('ResponseClass') === 'Error') {
				$code = $this->client->text($xp, 'm:ResponseCode', $msg);
				$out[] = ['email' => $emails[$i], 'busy' => [], 'error' => in_array($code, ['ErrorMailRecipientNotFound', 'ErrorNonExistentMailbox'], true)
					? 'disponibilités inconnues (personne extérieure ou adresse inconnue)'
					: 'disponibilités non partagées'];
				continue;
			}
			$busy = [];
			foreach ($xp->query('m:FreeBusyView/t:CalendarEventArray/t:CalendarEvent', $resp) as $node) {
				$type = (string)$this->client->text($xp, 't:BusyType', $node);
				if ($type === 'Free' || $type === 'NoData') {
					continue;
				}
				$busy[] = [
					'start' => substr((string)$this->client->text($xp, 't:StartTime', $node), 0, 19) . 'Z',
					'end' => substr((string)$this->client->text($xp, 't:EndTime', $node), 0, 19) . 'Z',
					'type' => $type,
				];
			}
			$out[] = ['email' => $emails[$i], 'busy' => $busy, 'error' => null];
		}
		return $out;
	}

	/**
	 * The first moment from $from where everybody is free for $minutes,
	 * on a working day (Monday to Friday) between $dayStart and $dayEnd
	 * (hours, in $tz), on the quarter hour; null within the period.
	 * Tentative is busy; people whose availability is unknown are ignored.
	 * @param list<array{busy: list<array{start: string, end: string, type: string}>}> $people
	 */
	public static function firstFreeSlot(array $people, \DateTimeImmutable $from, \DateTimeImmutable $until, int $minutes, \DateTimeZone $tz,
		int $dayStart = 8, int $dayEnd = 18): ?\DateTimeImmutable {
		$blocks = [];
		foreach ($people as $p) {
			foreach ($p['busy'] as $b) {
				$blocks[] = [(new \DateTimeImmutable($b['start']))->getTimestamp(), (new \DateTimeImmutable($b['end']))->getTimestamp()];
			}
		}
		$len = max(5, $minutes) * 60;
		$t = $from->setTimezone($tz);
		// next quarter hour
		$m = (int)$t->format('i');
		$t = $t->setTime((int)$t->format('H'), $m - $m % 15)->modify($m % 15 ? '+15 minutes' : '+0 minutes');
		while ($t < $until) {
			$h = (int)$t->format('G');
			if ((int)$t->format('N') >= 6 || $h >= $dayEnd) {
				$t = $t->modify('+1 day')->setTime($dayStart, 0);
				continue;
			}
			if ($h < $dayStart) {
				$t = $t->setTime($dayStart, 0);
				continue;
			}
			$s = $t->getTimestamp();
			$e = $s + $len;
			if ($e > $t->setTime($dayEnd, 0)->getTimestamp()) {
				$t = $t->modify('+1 day')->setTime($dayStart, 0);
				continue;
			}
			$clash = null;
			foreach ($blocks as [$bs, $be]) {
				if ($bs < $e && $be > $s) {
					$clash = max($clash ?? 0, $be);
				}
			}
			if ($clash === null) {
				return $t;
			}
			// after the meeting in the way, on the quarter hour
			$t = (new \DateTimeImmutable('@' . $clash))->setTimezone($tz);
			$m = (int)$t->format('i');
			if ($m % 15) {
				$t = $t->setTime((int)$t->format('H'), $m - $m % 15)->modify('+15 minutes');
			}
		}
		return null;
	}

	// ------------------------------------------------------------------
	// Sharing one of my calendars (folder permissions, without delegation)
	// ------------------------------------------------------------------

	/** Levels offered in the UI; others (Owner, Custom...) are kept as they are. */
	public const SHARE_LEVELS = EwsPermissions::CALENDAR_LEVELS;

	/**
	 * Who my calendar is shared with, as in Outlook's "Permissions" tab.
	 * @return array<int, array{user: string, name: string, level: string}>
	 */
	public function getPermissions(string $calendar): array {
		self::assertOwn($calendar);
		return (new EwsPermissions($this->client, EwsPermissions::CALENDAR))->get($this->folderXml($calendar));
	}

	/**
	 * Replace the permissions of my calendar.
	 * @param array<int, array{user: string, level: string}> $entries level "keep" = leave as it is
	 */
	public function setPermissions(string $calendar, array $entries, string $ownEmail): void {
		self::assertOwn($calendar);
		(new EwsPermissions($this->client, EwsPermissions::CALENDAR))->set($this->folderXml($calendar), $entries, $ownEmail);
	}

	private static function assertOwn(string $calendar): void {
		if (str_starts_with($calendar, 'shared:')) {
			throw new MailException('Seul le propriétaire d\'un agenda peut le partager');
		}
	}

	private static function utcNoZone(\DateTimeInterface $d): string {
		return (new \DateTimeImmutable('@' . $d->getTimestamp()))->format('Y-m-d\TH:i:s');
	}

	/**
	 * Events (recurrences expanded) overlapping [$start, $end[.
	 * @param string $calendar calendar key, see folderXml()
	 * @return array<int, array<string, mixed>>
	 */
	/**
	 * Does the event contain all the words searched (subject, location,
	 * organiser), whatever the accents and capitals?
	 * @param array<string, mixed> $event as returned by findEvents()
	 */
	public static function matches(array $event, string $query): bool {
		$fold = function (string $s): string {
			$s = mb_strtolower($s, 'UTF-8');
			$t = class_exists(\Normalizer::class) ? \Normalizer::normalize($s, \Normalizer::FORM_D) : $s;
			return (string)preg_replace('/\p{Mn}+/u', '', (string)$t);
		};
		$organizer = is_array($event['organizer'] ?? null) ? ($event['organizer']['name'] ?? '') . ' ' . ($event['organizer']['email'] ?? '') : '';
		$haystack = $fold(($event['subject'] ?? '') . ' ' . ($event['location'] ?? '') . ' ' . $organizer);
		$words = preg_split('/\s+/u', $fold(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		if ($words === []) {
			return false;
		}
		foreach ($words as $w) {
			if (!str_contains($haystack, $w)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Events of a calendar matching $query, from six months ago to six months
	 * ahead (Exchange cannot search and expand recurring events at once: the
	 * period is read, then filtered), soonest first.
	 * @return array<int, array<string, mixed>>
	 */
	public function searchEvents(string $query, string $calendar = 'calendar', ?\DateTimeImmutable $now = null): array {
		if (mb_strlen(trim($query)) < 2) {
			throw new MailException('Tapez au moins 2 lettres');
		}
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$found = array_values(array_filter($this->findEvents($now->modify('-183 days'), $now->modify('+183 days'), $calendar),
			fn ($e) => self::matches($e, $query)));
		usort($found, fn ($a, $b) => strcmp((string)$a['start'], (string)$b['start']));
		return array_slice($found, 0, 200);
	}

	public function findEvents(\DateTimeInterface $start, \DateTimeInterface $end, string $calendar = 'calendar'): array {
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
			. '<m:ParentFolderIds>' . $this->folderXml($calendar) . '</m:ParentFolderIds>'
			. '</m:FindItem>'
		);
		$events = [];
		foreach ($xp->query('//m:RootFolder/t:Items/t:CalendarItem') as $node) {
			$events[] = $this->parseEvent($xp, $node, false);
		}
		return $events;
	}

	/**
	 * Events of the own calendar starting between $from and $to, with their
	 * reminder (minutes before the start, -1 when there is none).
	 * @return list<array<string, mixed>>
	 */
	public function withReminders(\DateTimeImmutable $from, \DateTimeImmutable $to): array {
		$xp = $this->client->request(
			'<m:FindItem Traversal="Shallow">'
			. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
			. self::fields(array_merge(self::LIST_FIELDS, ['item:ReminderIsSet', 'item:ReminderMinutesBeforeStart']))
			. '</t:AdditionalProperties></m:ItemShape>'
			. '<m:CalendarView MaxEntriesReturned="200" StartDate="' . self::utc($from) . '" EndDate="' . self::utc($to) . '"/>'
			. '<m:ParentFolderIds>' . $this->folderXml('calendar') . '</m:ParentFolderIds>'
			. '</m:FindItem>'
		);
		$events = [];
		foreach ($xp->query('//m:RootFolder/t:Items/t:CalendarItem') as $node) {
			$e = $this->parseEvent($xp, $node, false);
			$e['reminder'] = $this->client->text($xp, 't:ReminderIsSet', $node) === 'true'
				? (int)$this->client->text($xp, 't:ReminderMinutesBeforeStart', $node) : -1;
			$events[] = $e;
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
	 * @param string $calendar calendar key, see folderXml()
	 * @return string id of the new event
	 */
	public function createEvent(array $data, string $calendar = 'calendar'): string {
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
			. '<m:SavedItemFolderId>' . $this->folderXml($calendar) . '</m:SavedItemFolderId>'
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
			'AbsoluteMonthlyRecurrence', 'RelativeMonthlyRecurrence' => 'monthly',
			'AbsoluteYearlyRecurrence', 'RelativeYearlyRecurrence' => 'yearly',
			default => 'other',
		};
		$days = trim((string)$value('DaysOfWeek', $pattern));
		$relative = in_array($pattern?->localName, ['RelativeMonthlyRecurrence', 'RelativeYearlyRecurrence'], true);
		// "the 2nd weekday", "the last weekend day": not one day of the week, kept as is
		if ($relative && !in_array($days, self::WEEKDAYS, true)) {
			$freq = 'other';
		}
		$endDate = $value('EndDate', $range);
		$count = $value('NumberOfOccurrences', $range);
		return [
			'freq' => $freq,
			'pattern' => $pattern?->localName,
			'interval' => max(1, (int)($value('Interval', $pattern) ?? 1)),
			'days' => $days === '' ? [] : preg_split('/\s+/', $days),
			'dayOfMonth' => $value('DayOfMonth', $pattern) !== null ? (int)$value('DayOfMonth', $pattern) : null,
			// monthly / yearly: on a date ("le 12") or on a weekday ("le 2e mardi", "le dernier vendredi")
			'by' => $relative ? (($value('DayOfWeekIndex', $pattern) === 'Last') ? 'last' : 'weekday') : 'date',
			'weekIndex' => $relative ? $value('DayOfWeekIndex', $pattern) : null,
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
	 * Move or resize an event (drag and drop): only its start and end change.
	 * $start / $end are local wall-clock times ("Y-m-d\TH:i"), or dates
	 * ("Y-m-d", inclusive last day) for an all-day event.
	 * @param bool $notify send the update to the attendees (meeting you organise)
	 */
	public function moveEvent(string $id, string $start, string $end, bool $allDay, bool $notify): void {
		$e = self::normalize(['start' => $start, 'end' => $end, 'allDay' => $allDay]);
		$c = $this->client;
		$set = fn (string $field, string $inner) => '<t:SetItemField><t:FieldURI FieldURI="' . $field . '"/><t:CalendarItem>' . $inner . '</t:CalendarItem></t:SetItemField>';
		$this->client->request(
			'<m:UpdateItem ConflictResolution="AlwaysOverwrite" SendMeetingInvitationsOrCancellations="' . ($notify ? 'SendToAllAndSaveCopy' : 'SendToNone') . '">'
			. '<m:ItemChanges><t:ItemChange><t:ItemId Id="' . $c->esc($id) . '"/><t:Updates>'
			. $set('calendar:Start', '<t:Start>' . $e['start'] . '</t:Start>')
			. $set('calendar:End', '<t:End>' . $e['end'] . '</t:End>')
			. '</t:Updates></t:ItemChange></m:ItemChanges></m:UpdateItem>'
		);
	}

	/**
	 * Delete an event. For an occurrence, $series = true deletes the whole series.
	 * Attendees of a meeting you organise receive a cancellation.
	 */
	/**
	 * @param bool $public in a calendar of the public folders: they have no
	 *        « Éléments supprimés », the event is deleted for good
	 */
	public function deleteEvent(string $id, bool $series = false, bool $public = false): void {
		$ids = $series
			? '<t:RecurringMasterItemId OccurrenceId="' . $this->client->esc($id) . '"/>'
			: '<t:ItemId Id="' . $this->client->esc($id) . '"/>';
		$this->client->request(
			'<m:DeleteItem DeleteType="' . ($public ? 'HardDelete' : 'MoveToDeletedItems') . '" SendMeetingCancellations="SendToAllAndSaveCopy">'
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
		$by = 'date';
		if ($freq === 'monthly' || $freq === 'yearly') {
			$by = (string)($r['by'] ?? 'date');
			if (!in_array($by, ['date', 'weekday', 'last'], true)) {
				throw new MailException('Répétition inconnue');
			}
			if ($by === 'last' && !self::inLastWeek(new \DateTimeImmutable($startDate))) {
				throw new MailException('Le premier événement n\'est pas le dernier de ce jour dans le mois');
			}
		}
		return ['freq' => $freq, 'interval' => $interval, 'days' => $days, 'by' => $by, 'until' => $until, 'count' => $until === null ? $count : null];
	}

	private const WEEK_INDEX = [1 => 'First', 2 => 'Second', 3 => 'Third', 4 => 'Fourth'];

	/** The date is one of the last 7 days of its month. */
	public static function inLastWeek(\DateTimeImmutable $d): bool {
		return (int)$d->format('j') + 7 > (int)$d->format('t');
	}

	/** "Second" for the 2nd Tuesday… ; a 5th one is the last. */
	public static function weekIndex(\DateTimeImmutable $d, string $by): string {
		$n = intdiv((int)$d->format('j') - 1, 7) + 1;
		return $by === 'last' || $n > 4 ? 'Last' : self::WEEK_INDEX[$n];
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
		$by = $r['by'] ?? 'date';
		$relative = '<t:DaysOfWeek>' . $start->format('l') . '</t:DaysOfWeek><t:DayOfWeekIndex>' . self::weekIndex($start, $by) . '</t:DayOfWeekIndex>';
		$pattern = match (true) {
			$r['freq'] === 'daily' => '<t:DailyRecurrence>' . $interval . '</t:DailyRecurrence>',
			$r['freq'] === 'weekly' => '<t:WeeklyRecurrence>' . $interval . '<t:DaysOfWeek>' . implode(' ', $r['days']) . '</t:DaysOfWeek></t:WeeklyRecurrence>',
			$r['freq'] === 'monthly' && $by !== 'date' => '<t:RelativeMonthlyRecurrence>' . $interval . $relative . '</t:RelativeMonthlyRecurrence>',
			$r['freq'] === 'monthly' => '<t:AbsoluteMonthlyRecurrence>' . $interval . '<t:DayOfMonth>' . $start->format('j') . '</t:DayOfMonth></t:AbsoluteMonthlyRecurrence>',
			$by !== 'date' => '<t:RelativeYearlyRecurrence>' . $relative . '<t:Month>' . $start->format('F') . '</t:Month></t:RelativeYearlyRecurrence>',
			default => '<t:AbsoluteYearlyRecurrence><t:DayOfMonth>' . $start->format('j') . '</t:DayOfMonth><t:Month>' . $start->format('F') . '</t:Month></t:AbsoluteYearlyRecurrence>',
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
