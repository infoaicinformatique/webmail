<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Exception\MailException;
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
	) {
	}

	public function isEnabled(string $userId, int $accountId): bool {
		return $this->config->getUserValue($userId, Application::APP_ID, 'remind_' . $accountId, '1') === '1';
	}

	public function setEnabled(string $userId, int $accountId, bool $enabled): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'remind_' . $accountId, $enabled ? '1' : '0');
	}

	public function forget(string $userId, int $accountId): void {
		$this->config->deleteUserValue($userId, Application::APP_ID, 'remind_seen_' . $accountId);
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

		$events = (new EwsCalendar($this->accounts->getClient($account)))
			->withReminders($now->modify('-5 minutes'), $now->modify('+' . self::HORIZON_HOURS . ' hours'));
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
				]);
			$this->notifications->notify($n);
			$seen[$objectId] = (string)$ev['end'];
			$sent++;
		}
		$this->config->setUserValue($userId, Application::APP_ID, $key, json_encode($seen));
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

	/** One notification per occurrence: a weekly meeting reminds every week. */
	public static function objectId(string $eventId, string $start): string {
		return hash('sha256', $eventId . '|' . $start);
	}
}
