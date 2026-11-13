<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Ews\EwsContacts;
use OCA\Webmail\Ews\TimeZones;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\MeetingReminderService;
use OCA\Webmail\Service\SharedFolderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Exchange calendar. "tz" is the browser's IANA time zone (e.g. Europe/Paris):
 * wall-clock times sent by the UI are interpreted in it.
 */
class CalendarController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private SharedFolderService $shared,
		private MeetingReminderService $reminders,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	/**
	 * @param string $calendar the calendar the request is about: a calendar of
	 *        the public folders is reached through the public folder mailbox
	 * @param bool $write the request changes something in it
	 */
	private function calendar(int $id, string $tz, string $calendar = '', bool $write = false): EwsCalendar {
		$account = $this->accounts->find((string)$this->userId, $id);
		if (str_starts_with($calendar, 'public:')) {
			$entry = $this->shared->find(SharedFolderService::PUBLIC_CALENDARS, (string)$this->userId, $id, '', substr($calendar, 7));
			if ($entry === null) {
				throw new MailException('Agenda des dossiers publics inconnu');
			}
			if ($write && !$entry['canEdit']) {
				throw new MailException('Cet agenda des dossiers publics est en lecture seule pour vous');
			}
			$client = $this->accounts->getPublicFolderClient($account);
		} else {
			$client = $this->accounts->getClient($account);
		}
		$client->setTimeZone(TimeZones::toWindows($tz));
		return new EwsCalendar($client);
	}

	private static function date(string $value): \DateTimeImmutable {
		try {
			return new \DateTimeImmutable($value);
		} catch (\Exception) {
			throw new MailException('Date invalide : %s', [$value]);
		}
	}

	/**
	 * The account's own calendars and the shared calendars added to it.
	 */
	#[NoAdminRequired]
	public function calendars(int $id): Response {
		return $this->handle(function () use ($id) {
			$own = $this->calendar($id, 'UTC')->listOwnCalendars();
			$shared = array_map(fn ($c) => [
				'key' => 'shared:' . $c['email'],
				'name' => $c['name'],
				'email' => $c['email'],
				'kind' => 'shared',
				'canEdit' => $c['canEdit'],
				'mode' => $c['mode'],
			], $this->shared->list(SharedFolderService::CALENDARS, (string)$this->userId, $id));
			$public = array_map(fn ($c) => [
				'key' => 'public:' . $c['folder'],
				'name' => $c['name'],
				'path' => $c['folderName'],
				'kind' => 'public',
				'canEdit' => $c['canEdit'],
				'mode' => 'full',
			], $this->shared->list(SharedFolderService::PUBLIC_CALENDARS, (string)$this->userId, $id));
			return ['calendars' => array_merge($own, $shared, $public)];
		});
	}

	/**
	 * Who one of my calendars is shared with (folder permissions).
	 */
	#[NoAdminRequired]
	public function permissions(int $id, string $calendar = 'calendar'): Response {
		return $this->handle(fn () => ['permissions' => $this->calendar($id, 'UTC')->getPermissions($calendar)]);
	}

	/**
	 * Share one of my calendars without delegation.
	 * @param array<int, array{user: string, level: string}> $permissions
	 */
	#[NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\UserRateLimit(limit: 30, period: 300)]
	public function setPermissions(int $id, array $permissions, string $calendar = 'calendar'): Response {
		return $this->handle(function () use ($id, $permissions, $calendar) {
			$account = $this->accounts->find((string)$this->userId, $id);
			$cal = $this->calendar($id, 'UTC');
			$cal->setPermissions($calendar, $permissions, $account->getEmail());
			return ['permissions' => $cal->getPermissions($calendar)];
		});
	}

	/**
	 * Add another person's calendar; the access level is detected.
	 */
	#[NoAdminRequired]
	public function addShared(int $id, string $email): Response {
		return $this->handle(function () use ($id, $email) {
			$email = strtolower(trim($email));
			if (preg_match('/<([^>]+)>/', $email, $m)) {
				$email = trim($m[1]);
			}
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				throw new MailException('Adresse e-mail invalide');
			}
			$account = $this->accounts->find((string)$this->userId, $id);
			if ($account->getEmail() === $email) {
				throw new MailException('C\'est votre propre agenda');
			}
			$client = $this->accounts->getClient($account);
			$access = (new EwsCalendar($client))->probeSharedCalendar($email);
			$name = $email;
			try {
				foreach ((new EwsContacts($client))->resolve($email) as $r) {
					if (strtolower($r['email']) === $email && $r['name'] !== '') {
						$name = $r['name'];
						break;
					}
				}
			} catch (MailException) {
				// the address is enough
			}
			$entry = ['email' => $email, 'name' => $name, 'mode' => $access['mode'], 'canEdit' => $access['canEdit']];
			$this->shared->save(SharedFolderService::CALENDARS, (string)$this->userId, $id, $entry);
			return ['key' => 'shared:' . $email, 'kind' => 'shared'] + $entry;
		});
	}

	#[NoAdminRequired]
	public function removeShared(int $id, string $email): Response {
		return $this->handle(function () use ($id, $email) {
			$this->accounts->find((string)$this->userId, $id); // ownership check
			$this->shared->remove(SharedFolderService::CALENDARS, (string)$this->userId, $id, $email);
			return ['status' => 'ok'];
		});
	}

	/**
	 * @param string $calendar calendar key ("calendar", a folder id, or "shared:<email>")
	 */
	#[NoAdminRequired]
	public function events(int $id, string $start, string $end, string $tz = 'UTC', string $calendar = 'calendar'): Response {
		return $this->handle(function () use ($id, $start, $end, $tz, $calendar) {
			$cal = $this->calendar($id, $tz, $calendar);
			if (str_starts_with($calendar, 'shared:')) {
				$entry = $this->shared->find(SharedFolderService::CALENDARS, (string)$this->userId, $id, substr($calendar, 7));
				if ($entry === null) {
					throw new MailException('Agenda partagé inconnu');
				}
				if ($entry['mode'] === 'freebusy') {
					// the events without details are named after their state: in the language of the user
					$l = $this->l10n();
					return ['events' => array_map(fn ($e) => in_array($e['subject'], EwsCalendar::FREE_BUSY_LABELS, true)
						? ['subject' => $l->t($e['subject'])] + $e : $e,
						$cal->freeBusy($entry['email'], self::date($start), self::date($end)))];
				}
			}
			return ['events' => $cal->findEvents(self::date($start), self::date($end), $calendar)];
		});
	}

	/**
	 * Room lists of the organisation, or (with $list) the rooms of one.
	 */
	#[NoAdminRequired]
	public function rooms(int $id, string $list = ''): Response {
		return $this->handle(function () use ($id, $list) {
			$cal = $this->calendar($id, 'UTC');
			return $list === '' ? ['lists' => $cal->roomLists()] : ['rooms' => $cal->rooms($list)];
		});
	}

	/**
	 * Scheduling assistant: the availability of the attendees (and of the
	 * account) on a day, and the first moment from $from when everybody
	 * is free for $minutes (working days, 8:00–18:00, two weeks ahead).
	 */
	#[NoAdminRequired]
	public function availability(int $id, string $emails, string $day, string $from, int $minutes = 60, string $tz = 'UTC'): Response {
		return $this->handle(function () use ($id, $emails, $day, $from, $minutes, $tz) {
			$account = $this->accounts->find((string)$this->userId, $id);
			try {
				$zone = new \DateTimeZone($tz);
			} catch (\Exception) {
				throw new MailException('Fuseau horaire inconnu');
			}
			$dayStart = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, $zone);
			$start = date_create_immutable($from);
			if ($dayStart === false || $start === false) {
				throw new MailException('Date invalide');
			}
			// never a moment already past
			$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
			if ($start < $now) {
				$start = $now;
			}
			$list = array_merge([$account->getEmail()], preg_split('/[,;\s]+/', $emails) ?: []);
			// one request (14 days at most): the day shown, and the search from $start
			$windowStart = min($dayStart, $start);
			$windowEnd = $windowStart->modify('+14 days');
			if ($dayStart->modify('+1 day') > $windowEnd) {
				// a day far ahead: shown in full, the search starts there
				$windowStart = $dayStart;
				$windowEnd = $dayStart->modify('+14 days');
			}
			$start = max($start, $windowStart);
			$searchEnd = $windowEnd;
			$people = $this->calendar($id, 'UTC')->availability($list, $windowStart, $windowEnd);
			$slot = EwsCalendar::firstFreeSlot(array_filter($people, fn ($p) => $p['error'] === null), $start, $searchEnd, max(15, min(600, $minutes)), $zone);
			return [
				'people' => array_map(fn ($p) => ($p['error'] !== null ? ['error' => $this->l10n()->t($p['error'])] : []) + $p
					+ ['self' => strtolower($p['email']) === strtolower($account->getEmail())], $people),
				'suggestion' => $slot === null ? null : [
					'start' => $slot->format('Y-m-d\TH:i'),
					'end' => $slot->modify('+' . max(15, min(600, $minutes)) . ' minutes')->format('Y-m-d\TH:i'),
				],
			];
		});
	}

	/**
	 * Search the events of a calendar (six months back and ahead).
	 */
	#[NoAdminRequired]
	public function search(int $id, string $q, string $tz = 'UTC', string $calendar = 'calendar'): Response {
		return $this->handle(function () use ($id, $q, $tz, $calendar) {
			if (str_starts_with($calendar, 'shared:')) {
				$entry = $this->shared->find(SharedFolderService::CALENDARS, (string)$this->userId, $id, substr($calendar, 7));
				if ($entry === null) {
					throw new MailException('Agenda partagé inconnu');
				}
				if ($entry['mode'] === 'freebusy') {
					return ['events' => []]; // only free / busy times: nothing to search
				}
			}
			return ['events' => $this->calendar($id, $tz, $calendar)->searchEvents($q, $calendar)];
		});
	}

	#[NoAdminRequired]
	public function event(int $id, string $eventId, string $tz = 'UTC', string $calendar = ''): Response {
		return $this->handle(fn () => $this->calendar($id, $tz, $calendar)->getEvent($eventId));
	}

	/**
	 * @param array<string, mixed> $event
	 */
	#[NoAdminRequired]
	public function create(int $id, array $event, string $tz = 'UTC', string $calendar = 'calendar'): Response {
		return $this->handle(function () use ($id, $event, $tz, $calendar) {
			if (str_starts_with($calendar, 'shared:')) {
				$entry = $this->shared->find(SharedFolderService::CALENDARS, (string)$this->userId, $id, substr($calendar, 7));
				if ($entry === null || !$entry['canEdit']) {
					throw new MailException('Vous ne pouvez pas ajouter d\'événement dans cet agenda');
				}
			}
			$eventId = $this->calendar($id, $tz, $calendar, true)->createEvent($event, $calendar);
			$this->reminders->changed($id);
			return ['id' => $eventId];
		});
	}

	/**
	 * The recurring series of an occurrence, with its recurrence rule.
	 */
	#[NoAdminRequired]
	public function series(int $id, string $eventId, string $tz = 'UTC', string $calendar = ''): Response {
		return $this->handle(fn () => $this->calendar($id, $tz, $calendar)->getSeries($eventId));
	}

	/**
	 * @param array<string, mixed> $event
	 * @param bool $series true: $eventId is a series master, the recurrence rule is updated too
	 */
	#[NoAdminRequired]
	public function update(int $id, string $eventId, array $event, string $tz = 'UTC', bool $series = false, string $calendar = ''): Response {
		return $this->handle(function () use ($id, $eventId, $event, $tz, $series, $calendar) {
			$this->calendar($id, $tz, $calendar, true)->updateEvent($eventId, $event, $series);
			$this->reminders->changed($id);
			return ['status' => 'ok'];
		});
	}

	/**
	 * Drag and drop: new start and end of an event.
	 */
	#[NoAdminRequired]
	public function move(int $id, string $eventId, string $start, string $end, bool $allDay = false, bool $notify = false, string $tz = 'UTC', string $calendar = ''): Response {
		return $this->handle(function () use ($id, $eventId, $start, $end, $allDay, $notify, $tz, $calendar) {
			$this->calendar($id, $tz, $calendar, true)->moveEvent($eventId, $start, $end, $allDay, $notify);
			$this->reminders->changed($id);
			return ['status' => 'ok'];
		});
	}

	#[NoAdminRequired]
	public function delete(int $id, string $eventId, bool $series = false, string $calendar = ''): Response {
		return $this->handle(function () use ($id, $eventId, $series, $calendar) {
			$this->calendar($id, 'UTC', $calendar, true)->deleteEvent($eventId, $series, str_starts_with($calendar, 'public:'));
			$this->reminders->changed($id);
			return ['status' => 'ok'];
		});
	}

	/**
	 * Browse the public folders for calendars.
	 */
	#[NoAdminRequired]
	public function publicFolders(int $id, string $parent = ''): Response {
		return $this->handle(function () use ($id, $parent) {
			$account = $this->accounts->find((string)$this->userId, $id);
			$added = array_column($this->shared->list(SharedFolderService::PUBLIC_CALENDARS, (string)$this->userId, $id), 'folder');
			$folders = (new EwsContacts($this->accounts->getPublicFolderClient($account)))->listPublicFolders($parent !== '' ? $parent : null, 'IPF.Appointment');
			foreach ($folders as &$f) {
				$f['added'] = in_array($f['id'], $added, true);
			}
			return ['folders' => $folders];
		});
	}

	/**
	 * Add a calendar of the public folders.
	 * @param string $path where it is, for display ("Société › Planning")
	 */
	#[NoAdminRequired]
	public function addPublic(int $id, string $folder, string $path = ''): Response {
		return $this->handle(function () use ($id, $folder, $path) {
			$account = $this->accounts->find((string)$this->userId, $id);
			$cal = (new EwsContacts($this->accounts->getPublicFolderClient($account)))->getPublicBook($folder, 'IPF.Appointment');
			$this->shared->save(SharedFolderService::PUBLIC_CALENDARS, (string)$this->userId, $id, [
				'email' => '', 'name' => $cal['name'], 'mode' => 'full', 'canEdit' => $cal['canEdit'],
				'folder' => $folder, 'folderName' => mb_substr(trim($path), 0, 300),
			]);
			return ['key' => 'public:' . $folder, 'name' => $cal['name'], 'canEdit' => $cal['canEdit']];
		});
	}

	#[NoAdminRequired]
	public function removePublic(int $id, string $folder): Response {
		return $this->handle(function () use ($id, $folder) {
			$this->accounts->find((string)$this->userId, $id);
			$this->shared->remove(SharedFolderService::PUBLIC_CALENDARS, (string)$this->userId, $id, '', $folder);
			return ['status' => 'ok'];
		});
	}

	/**
	 * @param string $eventId calendar item or meeting request (e-mail) id
	 * @param string $calendar calendar key of the item; "shared:<email>" answers on behalf of that person
	 */
	#[NoAdminRequired]
	public function respond(int $id, string $eventId, string $response, string $message = '', string $calendar = ''): Response {
		return $this->handle(function () use ($id, $eventId, $response, $message, $calendar) {
			// answering in a colleague's calendar = answering on their behalf:
			// only with edit rights on it (delegate / editor in Outlook)
			if (str_starts_with($calendar, 'shared:')) {
				$entry = $this->shared->find(SharedFolderService::CALENDARS, (string)$this->userId, $id, substr($calendar, 7));
				if ($entry === null || !$entry['canEdit']) {
					throw new MailException('Vous ne pouvez pas répondre au nom de cette personne : son agenda vous est partagé en lecture seule');
				}
			}
			$this->calendar($id, 'UTC', $calendar)->respond($eventId, $response, $message);
			$this->reminders->changed($id);
			return ['status' => 'ok'];
		});
	}
}
