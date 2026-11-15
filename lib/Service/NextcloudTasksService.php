<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Ews\EwsTasks;
use OCA\Webmail\Exception\MailException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VTodo;
use Sabre\VObject\Reader;

/**
 * The task lists of the Tasks app of Nextcloud (calendars with VTODO) in the Tâches of
 * the webmail, next to the Exchange ones: read and, where the user may, written — the
 * same tasks as in the app and on the phones synced with Nextcloud. A task keeps on
 * saving what the form does not show (sub-tasks, tags, other apps' fields).
 */
class NextcloudTasksService {
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

	/** @return list<array<string, mixed>> the lists of tasks of the user (own and shared with them) */
	public function lists(string $userId): array {
		if (!$this->available()) {
			return [];
		}
		$out = [];
		foreach ($this->backend()->getCalendarsForUser('principals/users/' . $userId) as $c) {
			if (!empty($c['{http://nextcloud.com/ns}deleted-at'])) {
				continue;
			}
			$components = $c['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'] ?? null;
			if (!is_object($components) || !method_exists($components, 'getValue') || !in_array('VTODO', (array)$components->getValue(), true)) {
				continue;
			}
			$name = trim((string)($c['{DAV:}displayname'] ?? '')) ?: (string)$c['uri'];
			$out[] = [
				'key' => NcCalendarRules::key((int)$c['id']),
				'name' => $name,
				'default' => false,
				'kind' => 'nextcloud',
				'canEdit' => empty($c[self::READ_ONLY]),
				'color' => NcCalendarRules::color($c['{http://apple.com/ns/ical/}calendar-color'] ?? null),
			];
		}
		return $out;
	}

	/** @return array<string, mixed> the list, checked to be the user's */
	private function list(string $userId, string $key, bool $write = false): array {
		foreach ($this->lists($userId) as $l) {
			if ($l['key'] === $key) {
				if ($write && !$l['canEdit']) {
					throw new MailException('Cette liste de tâches Nextcloud est en lecture seule pour vous');
				}
				$l['id'] = (int)NcCalendarRules::calendarId($key);
				return $l;
			}
		}
		throw new MailException('Liste de tâches Nextcloud inconnue');
	}

	/** @return list<array<string, mixed>> the tasks, as EwsTasks::list() gives them */
	public function tasks(string $userId, string $key, bool $withCompleted = false): array {
		$list = $this->list($userId, $key);
		$backend = $this->backend();
		$uris = $backend->calendarQuery($list['id'], [
			'name' => 'VCALENDAR',
			'comp-filters' => [['name' => 'VTODO', 'comp-filters' => [], 'prop-filters' => [], 'is-not-defined' => false, 'time-range' => null]],
			'prop-filters' => [],
			'is-not-defined' => false,
			'time-range' => null,
		]);
		$tasks = [];
		foreach (array_chunk($uris, 200) as $chunk) {
			foreach ($backend->getMultipleCalendarObjects($list['id'], $chunk) as $object) {
				try {
					$todo = self::todo(self::read($object));
				} catch (\Throwable) {
					continue;
				}
				if ($todo === null) {
					continue;
				}
				$task = self::task($todo, (string)$object['uri']);
				if ($withCompleted || !$task['done']) {
					$tasks[] = $task;
				}
			}
		}
		return EwsTasks::sort($tasks);
	}

	/** @return array<string, mixed> one task with its notes */
	public function get(string $userId, string $key, string $id): array {
		$list = $this->list($userId, $key);
		$todo = self::todo($this->load($list['id'], $id)) ?? throw new MailException('Tâche introuvable');
		return self::task($todo, $id) + ['notes' => (string)($todo->DESCRIPTION ?? '')];
	}

	/** @param array<string, mixed> $data the form */
	public function create(string $userId, string $key, array $data): string {
		$list = $this->list($userId, $key, true);
		$t = EwsTasks::normalize($data);
		$uid = self::uuid();
		$vcal = new VCalendar(['PRODID' => '-//Nextcloud//Webmail Exchange//FR']);
		/** @var VTodo $todo */
		$todo = $vcal->add('VTODO', ['UID' => $uid]);
		$todo->add('CREATED', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
		self::apply($todo, $t);
		$uri = $uid . '.ics';
		$this->backend()->createCalendarObject($list['id'], $uri, $vcal->serialize());
		return $uri;
	}

	/** @param array<string, mixed> $data the form */
	public function update(string $userId, string $key, string $id, array $data): void {
		$list = $this->list($userId, $key, true);
		$vcal = $this->load($list['id'], $id);
		$todo = self::todo($vcal) ?? throw new MailException('Tâche introuvable');
		self::apply($todo, EwsTasks::normalize($data));
		$this->backend()->updateCalendarObject($list['id'], $id, $vcal->serialize());
	}

	/** Done, or to do again. */
	public function complete(string $userId, string $key, string $id, bool $done): void {
		$list = $this->list($userId, $key, true);
		$vcal = $this->load($list['id'], $id);
		$todo = self::todo($vcal) ?? throw new MailException('Tâche introuvable');
		self::setDone($todo, $done);
		self::touch($todo);
		$this->backend()->updateCalendarObject($list['id'], $id, $vcal->serialize());
	}

	public function delete(string $userId, string $key, string $id): void {
		$list = $this->list($userId, $key, true);
		$this->load($list['id'], $id);
		$backend = $this->backend();
		try {
			$backend->deleteCalendarObject($list['id'], $id);
		} catch (\Sabre\DAV\Exception\Forbidden) {
			// the trash bin already has a deleted task of this name: deleted for good
			$backend->deleteCalendarObject($list['id'], $id, \OCA\DAV\CalDAV\CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
		}
	}

	// ------------------------------------------------------------------

	private function load(int $calendarId, string $uri): VCalendar {
		if ($uri === '' || str_contains($uri, '/')) {
			throw new MailException('Tâche introuvable');
		}
		$object = $this->backend()->getCalendarObject($calendarId, $uri);
		if (!is_array($object)) {
			throw new MailException('Tâche introuvable');
		}
		return self::read($object);
	}

	/** @param array<string, mixed> $object */
	private static function read(array $object): VCalendar {
		$data = $object['calendardata'] ?? '';
		$vcal = Reader::read(is_resource($data) ? (string)stream_get_contents($data) : (string)$data);
		if (!$vcal instanceof VCalendar) {
			throw new MailException('Tâche illisible');
		}
		return $vcal;
	}

	/** The task of an object (not one occurrence of a repeated one). */
	private static function todo(VCalendar $vcal): ?VTodo {
		foreach ($vcal->select('VTODO') as $t) {
			if ($t instanceof VTodo && $t->{'RECURRENCE-ID'} === null) {
				return $t;
			}
		}
		return null;
	}

	/** @return array<string, mixed> */
	private static function task(VTodo $t, string $uri): array {
		$percent = (int)(string)($t->{'PERCENT-COMPLETE'} ?? '0');
		$status = NcTaskRules::status((string)($t->STATUS ?? ''), $percent);
		$due = null;
		if ($t->DUE !== null) {
			$v = (string)$t->DUE->getValue();
			// the day of the due date (a time is kept by the Tasks app, the form shows the day)
			$due = $t->DUE->hasTime()
				? \DateTimeImmutable::createFromInterface($t->DUE->getDateTime())->format('Y-m-d')
				: substr($v, 0, 4) . '-' . substr($v, 4, 2) . '-' . substr($v, 6, 2);
		}
		$reminder = null;
		foreach ($t->select('VALARM') as $alarm) {
			if ($alarm->TRIGGER !== null && strtoupper((string)($alarm->TRIGGER['VALUE'] ?? '')) === 'DATE-TIME') {
				$reminder = \DateTimeImmutable::createFromInterface($alarm->TRIGGER->getDateTime())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
				break;
			}
		}
		$utc = fn ($p) => $p !== null ? \DateTimeImmutable::createFromInterface($p->getDateTime())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : null;
		return [
			'id' => $uri,
			'subject' => (string)($t->SUMMARY ?? ''),
			'importance' => NcTaskRules::importance((int)(string)($t->PRIORITY ?? '0')),
			'categories' => $t->CATEGORIES !== null ? array_values(array_map('strval', $t->CATEGORIES->getParts())) : [],
			'due' => $due,
			'status' => $status,
			'done' => $status === 'Completed',
			'percent' => $status === 'Completed' ? 100 : $percent,
			'completed' => $utc($t->COMPLETED),
			'recurring' => $t->RRULE !== null,
			'created' => $utc($t->CREATED),
			'reminder' => $reminder,
		];
	}

	/**
	 * The form into a VTODO; the rest is kept.
	 * @param array<string, mixed> $t EwsTasks::normalize()
	 */
	private static function apply(VTodo $todo, array $t): void {
		$todo->SUMMARY = $t['subject'];
		$todo->remove('DESCRIPTION');
		if (trim((string)$t['notes']) !== '') {
			$todo->add('DESCRIPTION', (string)$t['notes']);
		}
		$todo->remove('DUE');
		if ($t['due'] !== null) {
			$todo->add('DUE', str_replace('-', '', substr((string)$t['due'], 0, 10)), ['VALUE' => 'DATE']);
		}
		$todo->remove('PRIORITY');
		$priority = NcTaskRules::priority((string)$t['importance']);
		if ($priority > 0) {
			$todo->add('PRIORITY', $priority);
		}
		// the reminders written by the form (an absolute time); the others are kept
		foreach ($todo->select('VALARM') as $alarm) {
			if ($alarm->TRIGGER !== null && strtoupper((string)($alarm->TRIGGER['VALUE'] ?? '')) === 'DATE-TIME') {
				$todo->remove($alarm);
			}
		}
		if ($t['reminder'] !== null) {
			$alarm = $todo->add('VALARM', ['ACTION' => 'DISPLAY', 'DESCRIPTION' => $t['subject']]);
			$alarm->add('TRIGGER', new \DateTimeImmutable((string)$t['reminder']), ['VALUE' => 'DATE-TIME']);
		}
		$status = NcTaskRules::vtodoStatus((string)$t['status']);
		self::setDone($todo, $status === 'COMPLETED');
		if ($status !== 'COMPLETED') {
			$todo->STATUS = $status;
		}
		self::touch($todo);
	}

	private static function setDone(VTodo $todo, bool $done): void {
		$todo->remove('COMPLETED');
		if ($done) {
			$todo->STATUS = 'COMPLETED';
			$todo->{'PERCENT-COMPLETE'} = 100;
			$todo->add('COMPLETED', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
		} else {
			$todo->STATUS = 'NEEDS-ACTION';
			$todo->remove('PERCENT-COMPLETE');
		}
	}

	private static function touch(VTodo $todo): void {
		$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$todo->DTSTAMP = $now;
		$todo->{'LAST-MODIFIED'} = $now;
	}

	private static function uuid(): string {
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
	}
}
