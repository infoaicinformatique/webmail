<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Ews\EwsTasks;
use OCA\Webmail\Exception\MailException;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Reminders of the Exchange calendar as Nextcloud notifications: when an
 * event's reminder is due (the one set in Outlook or in the webmail,
 * "15 minutes before"…), a notification until the event is over.
 */
class MeetingReminderService {
	/** reminders are looked for this far ahead (Outlook offers up to 2 weeks; a day covers what a notification is for) */
	public const HORIZON_HOURS = 25;

	public function __construct(
		private AccountMapper $mapper,
		private AccountService $accounts,
		private IConfig $config,
		private IManager $notifications,
		private IUserManager $users,
		private LoggerInterface $logger,
		private ICacheFactory $cacheFactory,
		private MatrixService $matrix,
		private MeetingRoomService $rooms,
	) {
	}

	/** the link to join a meeting is looked for in its description for the meetings of the next hours only */
	public const JOIN_HOURS = 3;

	/**
	 * The job runs every minute; the coming events are asked to Exchange
	 * every 5 minutes only (and again as soon as the calendar is changed in
	 * the webmail): the due reminders are then found without a request.
	 */
	public const EVENTS_TTL = 300;

	/** The calendar was changed in the webmail: its events are asked again at the next check. */
	public function changed(int $accountId): void {
		$this->cacheFactory->createDistributed('webmail-reminders')->remove('ev_' . $accountId);
	}

	/** @return array<int, array<string, mixed>> */
	private function events(Account $account, \DateTimeImmutable $now): array {
		$cache = $this->cacheFactory->createDistributed('webmail-reminders');
		$key = 'ev_' . $account->getId();
		$hit = $cache->get($key);
		// still covering the next hours (the window reaches a little further than the horizon)
		if (is_array($hit) && ($hit['until'] ?? 0) >= $now->getTimestamp() + self::HORIZON_HOURS * 3600 - self::EVENTS_TTL
			&& ($hit['from'] ?? PHP_INT_MAX) <= $now->getTimestamp() - 300) {
			return $hit['events'];
		}
		$from = $now->modify('-10 minutes');
		$until = $now->modify('+' . self::HORIZON_HOURS . ' hours')->modify('+' . self::EVENTS_TTL . ' seconds');
		$calendar = new EwsCalendar($this->accounts->getClient($account, AccountService::BACKGROUND_TIMEOUT));
		$events = $calendar->withReminders($from, $until);
		$events = $this->withJoinLinks($calendar, $events, $now);
		$cache->set($key, ['from' => $from->getTimestamp(), 'until' => $until->getTimestamp(), 'events' => $events], self::EVENTS_TTL);
		return $events;
	}

	/**
	 * The link to join each coming meeting (« join »): in its place, else in its description
	 * (read for the meetings of the next hours: « Rejoindre la visio : https://… »).
	 * @param array<int, array<string, mixed>> $events
	 * @return array<int, array<string, mixed>>
	 */
	private function withJoinLinks(EwsCalendar $calendar, array $events, \DateTimeImmutable $now): array {
		try {
			$visio = (string)($this->matrix->effectiveVisio()['visio'] ?? '');
		} catch (\Throwable) {
			$visio = '';
		}
		$soon = $now->getTimestamp() + self::JOIN_HOURS * 3600;
		foreach ($events as $i => $ev) {
			$join = self::joinLink((string)($ev['location'] ?? ''), '', $visio);
			if ($join === null && empty($ev['allDay']) && empty($ev['isCancelled']) && !empty($ev['start'])
				&& (new \DateTimeImmutable((string)$ev['start']))->getTimestamp() <= $soon
				&& (new \DateTimeImmutable((string)($ev['end'] ?: $ev['start'])))->getTimestamp() >= $now->getTimestamp()) {
				try {
					$join = self::joinLink((string)($ev['location'] ?? ''), (string)($calendar->getEvent((string)$ev['id'])['body'] ?? ''), $visio);
				} catch (\Throwable) {
					// (gone in between: no link)
				}
			}
			$events[$i]['join'] = $join;
		}
		return $events;
	}

	/**
	 * The meetings of the user's accounts that begin within $ahead minutes, or began and are
	 * not over (for the banner « Réunion dans 5 min · Rejoindre »). From the events the
	 * reminders keep (asked to Exchange every 5 minutes at most).
	 * @return list<array{account: int, email: string, eventId: string, subject: string, start: string, end: string, join: ?string}>
	 */
	public function upcoming(string $userId, \DateTimeImmutable $now, int $ahead = 15): array {
		$out = [];
		foreach ($this->mapper->findAllForUser($userId) as $account) {
			try {
				$events = $this->events($account, $now);
			} catch (\Throwable $e) {
				continue;
			}
			foreach (self::soon($events, $now, $ahead) as $ev) {
				$out[] = [
					'account' => $account->getId(),
					'email' => $account->getEmail(),
					'eventId' => (string)$ev['id'],
					'subject' => (string)($ev['subject'] ?? ''),
					'start' => (string)$ev['start'],
					'end' => (string)$ev['end'],
					'join' => $ev['join'] ?? null,
				];
			}
		}
		usort($out, fn ($a, $b) => strcmp($a['start'], $b['start']));
		return $out;
	}

	/** The tasks to do with a reminder, asked to Exchange every 5 minutes at most (and again when changed here). */
	private function tasks(Account $account): array {
		$cache = $this->cacheFactory->createDistributed('webmail-reminders');
		$key = 'tk_' . $account->getId();
		$hit = $cache->get($key);
		if (is_array($hit)) {
			return $hit;
		}
		$tasks = array_values(array_filter(
			(new EwsTasks($this->accounts->getClient($account, AccountService::BACKGROUND_TIMEOUT)))->list(false),
			fn ($t) => $t['reminder'] !== null));
		// only what a reminder needs
		$tasks = array_map(fn ($t) => ['id' => $t['id'], 'subject' => $t['subject'], 'reminder' => $t['reminder'], 'due' => $t['due'], 'done' => $t['done']], $tasks);
		$cache->set($key, $tasks, self::EVENTS_TTL);
		return $tasks;
	}

	/** The tasks were changed in the webmail: asked again at the next check. */
	public function tasksChanged(int $accountId): void {
		$this->cacheFactory->createDistributed('webmail-reminders')->remove('tk_' . $accountId);
	}

	/**
	 * Task reminders in the bell, one per task and reminder time; gone when the task is done,
	 * deleted, or its reminder changed.
	 */
	private function checkTasks(Account $account, \DateTimeImmutable $now): int {
		$userId = $account->getUserId();
		$key = 'remind_tasks_' . $account->getId();
		$seen = json_decode($this->config->getUserValue($userId, Application::APP_ID, $key, '{}'), true);
		$seen = is_array($seen) ? $seen : [];
		$tasks = $this->tasks($account);
		$pending = [];
		foreach ($tasks as $t) {
			$pending[self::objectId((string)$t['id'], (string)$t['reminder'])] = true;
		}
		foreach (array_keys($seen) as $objectId) {
			if (!isset($pending[$objectId])) {
				$n = $this->notifications->createNotification();
				$n->setApp(Application::APP_ID)->setUser($userId)->setObject('task', $objectId);
				$this->notifications->markProcessed($n);
				unset($seen[$objectId]);
			}
		}
		$sent = 0;
		foreach (EwsTasks::dueReminders($tasks, $now) as $t) {
			$objectId = self::objectId((string)$t['id'], (string)$t['reminder']);
			if (isset($seen[$objectId])) {
				continue;
			}
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(\DateTime::createFromImmutable($now))
				->setObject('task', $objectId)
				->setSubject('task_reminder', [
					'account' => $account->getId(),
					'email' => $account->getEmail(),
					'taskId' => (string)$t['id'],
					'subject' => mb_substr((string)$t['subject'], 0, 200),
					'due' => (string)($t['due'] ?? ''),
				]);
			$this->notifications->notify($n);
			$seen[$objectId] = (string)$t['reminder'];
			$sent++;
		}
		$this->config->setUserValue($userId, Application::APP_ID, $key, json_encode($seen));
		return $sent;
	}

	public function isEnabled(string $userId, int $accountId): bool {
		return $this->config->getUserValue($userId, Application::APP_ID, 'remind_' . $accountId, '1') === '1';
	}

	public function setEnabled(string $userId, int $accountId, bool $enabled): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'remind_' . $accountId, $enabled ? '1' : '0');
	}

	public function forget(string $userId, int $accountId): void {
		$this->config->deleteUserValue($userId, Application::APP_ID, 'remind_seen_' . $accountId);
		$this->config->deleteUserValue($userId, Application::APP_ID, 'remind_tasks_' . $accountId);
	}

	public function checkAll(?\DateTimeImmutable $now = null): int {
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$sent = 0;
		foreach ($this->mapper->findAll() as $account) {
			$user = $this->users->get($account->getUserId());
			if ($user === null || !$user->isEnabled() || !$this->isEnabled($account->getUserId(), $account->getId())) {
				continue;
			}
			try {
				$sent += $this->check($account, $now);
			} catch (MailException $e) {
				$this->logger->info('Webmail: reminders of ' . $account->getEmail() . ' not checked: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			} catch (\Throwable $e) {
				$this->logger->warning('Webmail: reminders of ' . $account->getEmail() . ' not checked', ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
		return $sent;
	}

	public function check(Account $account, \DateTimeImmutable $now): int {
		$userId = $account->getUserId();
		$key = 'remind_seen_' . $account->getId();
		$seen = json_decode($this->config->getUserValue($userId, Application::APP_ID, $key, '{}'), true);
		$seen = is_array($seen) ? $seen : [];

		// the reminders of events now over leave the bell
		foreach (self::over($seen, $now) as $objectId) {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)->setUser($userId)->setObject('event', $objectId);
			$this->notifications->markProcessed($n);
			unset($seen[$objectId]);
		}

		$events = $this->events($account, $now);
		$sent = 0;
		foreach (self::due($events, $now) as $ev) {
			$objectId = self::objectId((string)$ev['id'], (string)$ev['start']);
			if (isset($seen[$objectId])) {
				continue;
			}
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(\DateTime::createFromImmutable($now))
				->setObject('event', $objectId)
				->setSubject('event_reminder', [
					'account' => $account->getId(),
					'email' => $account->getEmail(),
					'eventId' => (string)$ev['id'],
					'subject' => mb_substr((string)($ev['subject'] ?? ''), 0, 200),
					'start' => (string)$ev['start'],
					'allDay' => (bool)($ev['allDay'] ?? false),
					'location' => mb_substr((string)($ev['location'] ?? ''), 0, 200),
					'join' => (string)($ev['join'] ?? ''),
				]);
			$this->notifications->notify($n);
			$seen[$objectId] = (string)$ev['end'];
			$sent++;
		}
		$this->config->setUserValue($userId, Application::APP_ID, $key, json_encode($seen));
		// the meetings beginning now: told in their room of the chat
		$toldKey = 'room_told_' . $account->getId();
		$told = json_decode($this->config->getUserValue($userId, Application::APP_ID, $toldKey, '{}'), true);
		$told = $this->rooms->tellStarts($userId, $events, $now, is_array($told) ? $told : []);
		if ($told !== []) {
			$this->config->setUserValue($userId, Application::APP_ID, $toldKey, json_encode($told));
		} else {
			$this->config->deleteUserValue($userId, Application::APP_ID, $toldKey);
		}
		try {
			$sent += $this->checkTasks($account, $now);
		} catch (MailException $e) {
			// (a mailbox without the tasks folder, a server refusing: the meeting reminders still work)
			$this->logger->info('Webmail: task reminders of ' . $account->getEmail() . ' not checked: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
		return $sent;
	}

	// ------------------------------------------------------------------
	// Pure functions (tested without a server)
	// ------------------------------------------------------------------

	/**
	 * The events whose reminder is due: its time has come, the event has
	 * not started yet (or just did: the job runs every few minutes), it is
	 * not cancelled and the reminder was not turned off.
	 * @param array<int, array<string, mixed>> $events
	 * @return array<int, array<string, mixed>>
	 */
	public static function due(array $events, \DateTimeImmutable $now): array {
		$t = $now->getTimestamp();
		return array_values(array_filter($events, function (array $ev) use ($t): bool {
			if (($ev['reminder'] ?? -1) < 0 || !empty($ev['isCancelled']) || empty($ev['start'])) {
				return false;
			}
			$start = (new \DateTimeImmutable((string)$ev['start']))->getTimestamp();
			return $t >= $start - 60 * (int)$ev['reminder'] && $t < $start + 5 * 60;
		}));
	}

	/**
	 * @param array<string, string> $seen object id => end of the event
	 * @return string[] the object ids of the events that are over
	 */
	public static function over(array $seen, \DateTimeImmutable $now): array {
		return array_keys(array_filter($seen, fn ($end) => $end === '' || (new \DateTimeImmutable($end)) <= $now));
	}

	/**
	 * The meetings beginning within $ahead minutes, or begun and not over: not all-day, not cancelled.
	 * @param array<int, array<string, mixed>> $events
	 * @return array<int, array<string, mixed>>
	 */
	public static function soon(array $events, \DateTimeImmutable $now, int $ahead): array {
		$t = $now->getTimestamp();
		return array_values(array_filter($events, function (array $ev) use ($t, $ahead): bool {
			if (!empty($ev['allDay']) || !empty($ev['isCancelled']) || empty($ev['start'])) {
				return false;
			}
			$start = (new \DateTimeImmutable((string)$ev['start']))->getTimestamp();
			$end = (new \DateTimeImmutable((string)($ev['end'] ?: $ev['start'])))->getTimestamp();
			return $start <= $t + 60 * $ahead && $end > $t;
		}));
	}

	/**
	 * The link to join a meeting: a room of Nextcloud Talk, a link of the visio of the
	 * organisation ($visio: Element, Element Call, « https://meet…/{room} »), a known meeting
	 * service (Teams, Zoom, Meet, Webex, Jitsi), else a link given as its place.
	 */
	public static function joinLink(string $location, string $body, string $visio = ''): ?string {
		$text = $location . ' ' . $body;
		if (preg_match('~https?://[^\s<>"\']+/call/[A-Za-z0-9]+~', $text, $m)) {
			return $m[0];
		}
		preg_match_all('~https?://[^\s<>"\']+~', $text, $all);
		$links = array_map(fn ($l) => rtrim($l, '.,;)'), $all[0]);
		if ($visio !== '') {
			$start = str_contains($visio, '{room}') ? substr($visio, 0, (int)strpos($visio, '{room}')) : rtrim($visio, '/') . '/';
			foreach ($links as $l) {
				if (str_starts_with($l, $start)) {
					return $l;
				}
			}
		}
		foreach ($links as $l) {
			if (preg_match('~^https://([a-z0-9-]+\.)*(teams\.microsoft\.com|teams\.live\.com|zoom\.us|meet\.google\.com|webex\.com|whereby\.com|meet\.jit\.si)/~i', $l)
				|| str_contains($l, '/#/room/')) {
				return $l;
			}
		}
		return preg_match('~https?://[^\s<>"\']+~', $location, $m) ? rtrim($m[0], '.,;)') : null;
	}

	/** One notification per occurrence: a weekly meeting reminds every week. */
	public static function objectId(string $eventId, string $start): string {
		return hash('sha256', $eventId . '|' . $start);
	}
}
