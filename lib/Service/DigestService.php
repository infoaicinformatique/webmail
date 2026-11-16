<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\AccountMapper;
use OCP\IConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * « Résumé du matin »: the day in one notification, at the hour each user chose
 * (all their accounts together); a click opens « Aujourd'hui ».
 */
class DigestService {
	private const HOUR = 'digest_hour';
	private const LAST = 'digest_last';
	/** unread messages of each inbox looked at for the important ones */
	private const UNREAD_READ = 100;

	public function __construct(
		private AccountMapper $mapper,
		private AccountService $accounts,
		private TodayService $today,
		private IConfig $config,
		private INotificationManager $notifications,
		private LoggerInterface $logger,
	) {
	}

	/** 0: off */
	public function hour(string $userId): int {
		return (int)$this->config->getUserValue($userId, Application::APP_ID, self::HOUR, '0');
	}

	public function setHour(string $userId, int $hour): void {
		if ($hour === 0) {
			$this->config->deleteUserValue($userId, Application::APP_ID, self::HOUR);
			return;
		}
		$this->config->setUserValue($userId, Application::APP_ID, self::HOUR, (string)$hour);
		// chosen after its hour today: from tomorrow on
		$local = new \DateTimeImmutable('now', $this->zone($userId));
		if ((int)$local->format('G') >= $hour) {
			$this->config->setUserValue($userId, Application::APP_ID, self::LAST, $local->format('Y-m-d'));
		}
	}

	private function zone(string $userId): \DateTimeZone {
		try {
			return new \DateTimeZone($this->config->getUserValue($userId, 'core', 'timezone', '') ?: date_default_timezone_get());
		} catch (\Exception) {
			return new \DateTimeZone('UTC');
		}
	}

	/** Every user whose hour has come: their summary sent (once a day). @return int how many sent */
	public function checkAll(?\DateTimeImmutable $now = null): int {
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$users = array_unique(array_map(fn ($a) => $a->getUserId(), $this->mapper->findAll()));
		$sent = 0;
		foreach ($users as $userId) {
			$hour = $this->hour($userId);
			$local = $now->setTimezone($this->zone($userId));
			if (!Digest::due($local, $hour, $this->config->getUserValue($userId, Application::APP_ID, self::LAST, ''))) {
				continue;
			}
			// marked first: a failure is not tried again every quarter of an hour
			$this->config->setUserValue($userId, Application::APP_ID, self::LAST, $local->format('Y-m-d'));
			try {
				$sent += $this->send($userId, $now) ? 1 : 0;
			} catch (\Throwable $e) {
				$this->logger->info('Webmail: morning summary of ' . $userId . ' not sent: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
		return $sent;
	}

	/**
	 * The summary of the day now (also « Recevoir le résumé maintenant »).
	 * @return array{meetings: int, first: string, firstStart: string, unread: int, important: int, toReply: int, tasks: int, flagged: int}
	 */
	public function counts(string $userId, ?\DateTimeImmutable $now = null): array {
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$today = $this->today->today($userId, $this->zone($userId)->getName(), $now);
		$unread = 0;
		$important = 0;
		foreach ($this->mapper->findAllForUser($userId) as $account) {
			try {
				$found = $this->accounts->getClient($account, AccountService::BACKGROUND_TIMEOUT)->findItems('inbox', 0, self::UNREAD_READ, 'isread:false');
			} catch (\Throwable $e) {
				$this->logger->info('Webmail: unread of ' . $account->getEmail() . ' not counted: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				continue;
			}
			$vip = VipList::decode($this->config->getUserValue($userId, Application::APP_ID, VipList::PREF . $account->getId(), '[]'));
			// (the search may also give read ones: only the unread)
			$items = array_filter($found['items'], fn ($m) => empty($m['isRead']));
			$unread += max(count($items), (int)$found['total']);
			$important += count(array_filter($items, fn ($m) => Digest::important($m, $vip)));
		}
		return Digest::counts($today, $unread, $important, $now);
	}

	/** @return bool false: nothing to say today */
	public function send(string $userId, ?\DateTimeImmutable $now = null): bool {
		$c = $this->counts($userId, $now);
		// yesterday's summary goes away
		$old = $this->notifications->createNotification();
		$old->setApp(Application::APP_ID)->setUser($userId)->setObject('digest', 'day');
		$this->notifications->markProcessed($old);
		if (Digest::isEmpty($c)) {
			return false;
		}
		$n = $this->notifications->createNotification();
		$n->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('digest', 'day')
			->setSubject('digest', $c);
		$this->notifications->notify($n);
		return true;
	}
}
