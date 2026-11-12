<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Ews\EwsContacts;
use OCA\Webmail\Ews\TimeZones;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AccountService;
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
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	private function calendar(int $id, string $tz): EwsCalendar {
		$client = $this->accounts->getClient($this->accounts->find((string)$this->userId, $id));
		$client->setTimeZone(TimeZones::toWindows($tz));
		return new EwsCalendar($client);
	}

	private static function date(string $value): \DateTimeImmutable {
		try {
			return new \DateTimeImmutable($value);
		} catch (\Exception) {
			throw new MailException('Date invalide : ' . $value);
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
			return ['calendars' => array_merge($own, $shared)];
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
			$cal = $this->calendar($id, $tz);
			if (str_starts_with($calendar, 'shared:')) {
				$entry = $this->shared->find(SharedFolderService::CALENDARS, (string)$this->userId, $id, substr($calendar, 7));
				if ($entry === null) {
					throw new MailException('Agenda partagé inconnu');
				}
				if ($entry['mode'] === 'freebusy') {
					return ['events' => $cal->freeBusy($entry['email'], self::date($start), self::date($end))];
				}
			}
			return ['events' => $cal->findEvents(self::date($start), self::date($end), $calendar)];
		});
	}

	#[NoAdminRequired]
	public function event(int $id, string $eventId, string $tz = 'UTC'): Response {
		return $this->handle(fn () => $this->calendar($id, $tz)->getEvent($eventId));
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
			return ['id' => $this->calendar($id, $tz)->createEvent($event, $calendar)];
		});
	}

	/**
	 * The recurring series of an occurrence, with its recurrence rule.
	 */
	#[NoAdminRequired]
	public function series(int $id, string $eventId, string $tz = 'UTC'): Response {
		return $this->handle(fn () => $this->calendar($id, $tz)->getSeries($eventId));
	}

	/**
	 * @param array<string, mixed> $event
	 * @param bool $series true: $eventId is a series master, the recurrence rule is updated too
	 */
	#[NoAdminRequired]
	public function update(int $id, string $eventId, array $event, string $tz = 'UTC', bool $series = false): Response {
		return $this->handle(function () use ($id, $eventId, $event, $tz, $series) {
			$this->calendar($id, $tz)->updateEvent($eventId, $event, $series);
			return ['status' => 'ok'];
		});
	}

	/**
	 * Drag and drop: new start and end of an event.
	 */
	#[NoAdminRequired]
	public function move(int $id, string $eventId, string $start, string $end, bool $allDay = false, bool $notify = false, string $tz = 'UTC'): Response {
		return $this->handle(function () use ($id, $eventId, $start, $end, $allDay, $notify, $tz) {
			$this->calendar($id, $tz)->moveEvent($eventId, $start, $end, $allDay, $notify);
			return ['status' => 'ok'];
		});
	}

	#[NoAdminRequired]
	public function delete(int $id, string $eventId, bool $series = false): Response {
		return $this->handle(function () use ($id, $eventId, $series) {
			$this->calendar($id, 'UTC')->deleteEvent($eventId, $series);
			return ['status' => 'ok'];
		});
	}

	/**
	 * @param string $eventId calendar item or meeting request (e-mail) id
	 */
	#[NoAdminRequired]
	public function respond(int $id, string $eventId, string $response, string $message = ''): Response {
		return $this->handle(function () use ($id, $eventId, $response, $message) {
			$this->calendar($id, 'UTC')->respond($eventId, $response, $message);
			return ['status' => 'ok'];
		});
	}
}
