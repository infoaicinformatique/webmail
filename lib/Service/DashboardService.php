<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Exception\MailException;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
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
		private IL10N $l,
	) {
	}

	/**
	 * Newest unread messages of the inboxes, newest first.
	 * @return array<int, array{accountId: int, account: string, id: string, subject: string, from: string, date: string}>
	 */
	public function unread(string $userId, int $limit = 7): array {
		return $this->cached($userId, 'unread', function (array $accounts) use ($limit) {
			$lists = [];
			$complete = true;
			foreach ($accounts as $account) {
				try {
					$r = $this->accounts->getClient($account)->findItems('inbox', 0, $limit, EwsClient::searchQuery(null, ['unread' => true]));
				} catch (MailException $e) {
					$this->logger->warning('Webmail dashboard: ' . $account->getEmail() . ': ' . $e->getMessage());
					$complete = false;
					continue;
				}
				$lists[] = ['id' => $account->getId(), 'email' => $account->getEmail(), 'items' => $r['items']];
			}
			return [self::mergeUnread($lists, $limit, $this->l), $complete];
		});
	}

	/**
	 * Next events of the main calendars over the coming week, soonest first.
	 * @return array<int, array{accountId: int, account: string, id: string, subject: string, start: string, end: string, allDay: bool, location: string}>
	 */
	public function upcoming(string $userId, int $limit = 7, ?\DateTimeImmutable $now = null): array {
		return $this->cached($userId, 'upcoming', function (array $accounts) use ($limit, $now) {
			$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
			$out = [];
			$complete = true;
			foreach ($accounts as $account) {
				try {
					$events = (new EwsCalendar($this->accounts->getClient($account)))->findEvents($now, $now->modify('+7 days'));
				} catch (MailException $e) {
					$this->logger->warning('Webmail dashboard: ' . $account->getEmail() . ': ' . $e->getMessage());
					$complete = false;
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
						'subject' => $e['subject'] !== '' ? $e['subject'] : $this->l->t('(sans titre)'),
						'start' => (string)$e['start'],
						'end' => (string)$e['end'],
						'allDay' => (bool)$e['allDay'],
						'location' => $e['location'],
					];
				}
			}
			usort($out, fn ($a, $b) => strtotime($a['start']) <=> strtotime($b['start']));
			return [array_slice($out, 0, $limit), $complete];
		});
	}

	/**
	 * The unread messages of each account, merged newest first.
	 * @param array<int, array{id: int, email: string, items: array<int, array<string, mixed>>}> $lists
	 * @return array<int, array{accountId: int, account: string, id: string, subject: string, from: string, date: string}>
	 */
	public static function mergeUnread(array $lists, int $limit, ?IL10N $l = null): array {
		$out = [];
		foreach ($lists as $list) {
			foreach ($list['items'] as $m) {
				$from = (string)($m['from']['name'] ?? '');
				$out[] = [
					'accountId' => $list['id'],
					'account' => $list['email'],
					'id' => (string)$m['id'],
					'subject' => (string)$m['subject'] !== '' ? (string)$m['subject'] : L10n::t($l, '(sans objet)'),
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
		return self::label($start, $allDay, new \DateTimeZone($this->config->getUserValue($userId, 'core', 'timezone', '') ?: date_default_timezone_get()), $now, $this->l);
	}

	public static function label(string $start, bool $allDay, \DateTimeZone $tz, ?\DateTimeImmutable $now = null, ?IL10N $l = null): string {
		$d = (new \DateTimeImmutable($start))->setTimezone($tz);
		$today = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz)->setTime(0, 0);
		$days = (int)$today->diff($d->setTime(0, 0))->format('%r%a');
		$day = match (true) {
			$days === 0 => L10n::t($l, 'Aujourd\'hui'),
			$days === 1 => L10n::t($l, 'Demain'),
			default => self::dayName((int)$d->format('w'), $l) . ' ' . $d->format('j') . ' ' . self::monthName((int)$d->format('n'), $l),
		};
		return $allDay ? L10n::t($l, '%s, toute la journée', [$day]) : $day . ' ' . $d->format('H:i');
	}

	/** "lun." (0: Sunday, as date('w')) */
	public static function dayName(int $w, ?IL10N $l = null): string {
		return match ($w % 7) {
			0 => L10n::t($l, 'dim.'),
			1 => L10n::t($l, 'lun.'),
			2 => L10n::t($l, 'mar.'),
			3 => L10n::t($l, 'mer.'),
			4 => L10n::t($l, 'jeu.'),
			5 => L10n::t($l, 'ven.'),
			default => L10n::t($l, 'sam.'),
		};
	}

	/** "janv." (1: January, as date('n')) */
	public static function monthName(int $n, ?IL10N $l = null): string {
		return match ($n) {
			1 => L10n::t($l, 'janv.'),
			2 => L10n::t($l, 'févr.'),
			3 => L10n::t($l, 'mars'),
			4 => L10n::t($l, 'avr.'),
			5 => L10n::t($l, 'mai'),
			6 => L10n::t($l, 'juin'),
			7 => L10n::t($l, 'juil.'),
			8 => L10n::t($l, 'août'),
			9 => L10n::t($l, 'sept.'),
			10 => L10n::t($l, 'oct.'),
			11 => L10n::t($l, 'nov.'),
			default => L10n::t($l, 'déc.'),
		};
	}

	/**
	 * A minute of cache, per user and per set of accounts (adding or removing
	 * an account is seen at once). Nothing is kept when there is no account
	 * or when an account did not answer.
	 * @param callable(array<int, \OCA\Webmail\Db\Account>): array{0: array, 1: bool} $load the result, and whether every account answered
	 */
	private function cached(string $userId, string $what, callable $load): array {
		$accounts = $this->accounts->findAll($userId);
		if ($accounts === []) {
			return [];
		}
		$cache = $this->cacheFactory->createDistributed('webmail-dashboard');
		$key = $what . '_' . md5($userId . '|' . implode(',', array_map(fn ($a) => $a->getId(), $accounts)));
		$value = $cache->get($key);
		if (is_array($value)) {
			return $value;
		}
		[$value, $complete] = $load($accounts);
		if ($complete) {
			$cache->set($key, $value, self::TTL);
		}
		return $value;
	}
}
