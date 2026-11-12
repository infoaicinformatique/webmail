<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Exception\MailException;
use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * What the Nextcloud dashboard shows: unread messages and the next events
 * of all the user's Exchange accounts, kept a minute in cache.
 */
class DashboardService {
	private const TTL = 60;

	public function __construct(
		private AccountService $accounts,
		private ICacheFactory $cacheFactory,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Newest unread messages of the inboxes, newest first.
	 * @return array<int, array{accountId: int, account: string, id: string, subject: string, from: string, date: string}>
	 */
	public function unread(string $userId, int $limit = 7): array {
		return $this->cached($userId, 'unread', function () use ($userId, $limit) {
			$lists = [];
			foreach ($this->accounts->findAll($userId) as $account) {
				try {
					$r = $this->accounts->getClient($account)->findItems('inbox', 0, $limit, EwsClient::searchQuery(null, ['unread' => true]));
				} catch (MailException $e) {
					$this->logger->info('Webmail dashboard: ' . $account->getEmail() . ': ' . $e->getMessage());
					continue;
				}
				$lists[] = ['id' => $account->getId(), 'email' => $account->getEmail(), 'items' => $r['items']];
			}
			return self::mergeUnread($lists, $limit);
		});
	}

	/**
	 * Next events of the main calendars over the coming week, soonest first.
	 * @return array<int, array{accountId: int, account: string, id: string, subject: string, start: string, end: string, allDay: bool, location: string}>
	 */
	public function upcoming(string $userId, int $limit = 7, ?\DateTimeImmutable $now = null): array {
		return $this->cached($userId, 'upcoming', function () use ($userId, $limit, $now) {
			$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
			$out = [];
			foreach ($this->accounts->findAll($userId) as $account) {
				try {
					$events = (new EwsCalendar($this->accounts->getClient($account)))->findEvents($now, $now->modify('+7 days'));
				} catch (MailException $e) {
					$this->logger->info('Webmail dashboard: ' . $account->getEmail() . ': ' . $e->getMessage());
					continue;
				}
				foreach ($events as $e) {
					if ($e['isCancelled'] || ($e['end'] !== null && new \DateTimeImmutable($e['end']) <= $now)) {
						continue;
					}
					$out[] = [
						'accountId' => $account->getId(),
						'account' => $account->getEmail(),
						'id' => (string)$e['id'],
						'subject' => $e['subject'] !== '' ? $e['subject'] : '(sans titre)',
						'start' => (string)$e['start'],
						'end' => (string)$e['end'],
						'allDay' => (bool)$e['allDay'],
						'location' => $e['location'],
					];
				}
			}
			usort($out, fn ($a, $b) => strtotime($a['start']) <=> strtotime($b['start']));
			return array_slice($out, 0, $limit);
		});
	}

	/**
	 * The unread messages of each account, merged newest first.
	 * @param array<int, array{id: int, email: string, items: array<int, array<string, mixed>>}> $lists
	 * @return array<int, array{accountId: int, account: string, id: string, subject: string, from: string, date: string}>
	 */
	public static function mergeUnread(array $lists, int $limit): array {
		$out = [];
		foreach ($lists as $l) {
			foreach ($l['items'] as $m) {
				$from = (string)($m['from']['name'] ?? '');
				$out[] = [
					'accountId' => $l['id'],
					'account' => $l['email'],
					'id' => (string)$m['id'],
					'subject' => (string)$m['subject'] !== '' ? (string)$m['subject'] : '(sans objet)',
					'from' => $from !== '' ? $from : (string)($m['from']['email'] ?? ''),
					'date' => (string)$m['date'],
				];
			}
		}
		usort($out, fn ($a, $b) => strcmp($b['date'], $a['date']));
		return array_slice($out, 0, $limit);
	}

	/** "Aujourd'hui 14:00", "Demain, toute la journée", "jeu. 20 nov. 09:30" in the user's time zone. */
	public function when(string $userId, string $start, bool $allDay, ?\DateTimeImmutable $now = null): string {
		return self::label($start, $allDay, new \DateTimeZone($this->config->getUserValue($userId, 'core', 'timezone', '') ?: date_default_timezone_get()), $now);
	}

	public static function label(string $start, bool $allDay, \DateTimeZone $tz, ?\DateTimeImmutable $now = null): string {
		$d = (new \DateTimeImmutable($start))->setTimezone($tz);
		$today = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz)->setTime(0, 0);
		$days = (int)$today->diff($d->setTime(0, 0))->format('%r%a');
		$dayNames = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
		$months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
		$day = match (true) {
			$days === 0 => 'Aujourd\'hui',
			$days === 1 => 'Demain',
			default => $dayNames[(int)$d->format('w')] . ' ' . $d->format('j') . ' ' . $months[(int)$d->format('n') - 1],
		};
		return $day . ($allDay ? ', toute la journée' : ' ' . $d->format('H:i'));
	}

	/**
	 * @template T
	 * @param callable(): T $load
	 * @return T
	 */
	private function cached(string $userId, string $what, callable $load): mixed {
		$cache = $this->cacheFactory->createDistributed('webmail-dashboard');
		$key = $what . '_' . md5($userId);
		$value = $cache->get($key);
		if (is_array($value)) {
			return $value;
		}
		$value = $load();
		$cache->set($key, $value, self::TTL);
		return $value;
	}
}
