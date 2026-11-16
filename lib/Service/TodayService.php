<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Ews\EwsTasks;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * « Aujourd'hui »: what the day holds, all the accounts together: the meetings of the day
 * (with the link to join them), the tasks due (or late), the messages followed up whose due
 * date has come, the messages put aside that come back today.
 */
class TodayService {
	/** at most this many items of each kind (the view is a summary, the modules hold the rest) */
	public const LIMIT = 30;

	public function __construct(
		private AccountMapper $mapper,
		private AccountService $accounts,
		private SnoozeService $snoozes,
		private ReplyLaterService $replies,
		private MatrixService $matrix,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{date: string, meetings: list<array<string, mixed>>, tasks: list<array<string, mixed>>,
	 *   flagged: list<array<string, mixed>>, snoozed: list<array<string, mixed>>, failed: list<string>}
	 */
	public function today(string $userId, string $tz, ?\DateTimeImmutable $now = null): array {
		$zone = new \DateTimeZone($tz);
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$local = $now->setTimezone($zone);
		$dayStart = $local->setTime(0, 0);
		$dayEnd = $dayStart->modify('+1 day');
		$today = $dayStart->format('Y-m-d');
		try {
			$visio = (string)($this->matrix->effectiveVisio()['visio'] ?? '');
		} catch (\Throwable) {
			$visio = '';
		}
		$out = ['date' => $today, 'meetings' => [], 'tasks' => [], 'flagged' => [], 'snoozed' => [], 'toReply' => [], 'failed' => []];
		foreach ($this->mapper->findAllForUser($userId) as $account) {
			try {
				$client = $this->accounts->getClient($account);
				$out['meetings'] = array_merge($out['meetings'], $this->meetings($account, $client, $dayStart, $dayEnd, $now, $visio));
				$out['tasks'] = array_merge($out['tasks'], $this->tasks($account, $client, $today));
				$out['flagged'] = array_merge($out['flagged'], $this->flagged($account, $client, $today));
				$out['snoozed'] = array_merge($out['snoozed'], $this->snoozed($account, $client, $now, $dayEnd));
				$out['toReply'] = array_merge($out['toReply'], array_map(fn ($e) => self::account($account) + $e, $this->replies->forToday($account)));
			} catch (\Throwable $e) {
				$this->logger->info('Webmail: today of ' . $account->getEmail() . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
				$out['failed'][] = $account->getEmail();
			}
		}
		usort($out['meetings'], fn ($a, $b) => [!$a['allDay'], $a['start']] <=> [!$b['allDay'], $b['start']]);
		usort($out['tasks'], fn ($a, $b) => strcmp((string)$a['due'], (string)$b['due']));
		usort($out['flagged'], fn ($a, $b) => strcmp((string)$a['due'], (string)$b['due']));
		usort($out['snoozed'], fn ($a, $b) => strcmp($a['until'], $b['until']));
		usort($out['toReply'], fn ($a, $b) => strcmp($a['until'], $b['until']));
		foreach (['meetings', 'tasks', 'flagged', 'snoozed', 'toReply'] as $k) {
			$out[$k] = array_slice($out[$k], 0, self::LIMIT);
		}
		return $out;
	}

	/** @return list<array<string, mixed>> */
	private function meetings(Account $account, EwsClient $client, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now, string $visio): array {
		$out = [];
		$calendar = new EwsCalendar($client);
		foreach ($calendar->findEvents($from, $to) as $e) {
			if ($e['isCancelled']) {
				continue;
			}
			$join = MeetingReminderService::joinLink((string)$e['location'], '', $visio);
			$end = (string)($e['end'] ?? $e['start']);
			// the description read only for the meetings still to come or under way (where the link is)
			if ($join === null && !$e['allDay'] && new \DateTimeImmutable($end) > $now) {
				try {
					$join = MeetingReminderService::joinLink((string)$e['location'], (string)($calendar->getEvent((string)$e['id'])['body'] ?? ''), $visio);
				} catch (\Throwable) {
				}
			}
			$out[] = self::account($account) + [
				'id' => (string)$e['id'],
				'subject' => (string)$e['subject'],
				'start' => (string)$e['start'],
				'end' => $end,
				'allDay' => (bool)$e['allDay'],
				'location' => (string)$e['location'],
				'join' => $join,
				'over' => new \DateTimeImmutable($end) <= $now,
			];
		}
		return $out;
	}

	/** The tasks to do due today, or late. @return list<array<string, mixed>> */
	private function tasks(Account $account, EwsClient $client, string $today): array {
		$out = [];
		foreach ((new EwsTasks($client))->list(false) as $t) {
			if ($t['done'] || $t['due'] === null || $t['due'] > $today) {
				continue;
			}
			$out[] = self::account($account) + ['id' => (string)$t['id'], 'subject' => (string)$t['subject'], 'due' => (string)$t['due'], 'late' => $t['due'] < $today];
		}
		return $out;
	}

	/** The messages of the inbox followed up, due today (Y-m-d) or before. @return list<array<string, mixed>> */
	private function flagged(Account $account, EwsClient $client, string $today): array {
		$out = [];
		foreach ($client->findItems('inbox', 0, 100, 'isflagged:true')['items'] as $m) {
			$due = (string)($m['flagDue'] ?? '');
			if (empty($m['flagged']) || $due === '' || $due > $today) {
				continue;
			}
			$out[] = self::account($account) + ['id' => (string)$m['id'], 'subject' => (string)$m['subject'], 'from' => $m['from'], 'due' => $due, 'late' => $due < $today];
		}
		return $out;
	}

	/** The messages put aside that come back later today. @return list<array<string, mixed>> */
	private function snoozed(Account $account, EwsClient $client, \DateTimeImmutable $now, \DateTimeImmutable $dayEnd): array {
		$list = array_filter($this->snoozes->list($account->getUserId(), $account->getId()),
			fn ($s) => ($t = new \DateTimeImmutable($s['until'])) > $now && $t < $dayEnd);
		if ($list === []) {
			return [];
		}
		$folder = $this->config->getUserValue($account->getUserId(), Application::APP_ID, 'snooze_folder_' . $account->getId(), '');
		$found = [];
		if ($folder !== '') {
			foreach ($client->findItems($folder, 0, 200)['items'] as $m) {
				$found[$m['id']] = $m;
			}
		}
		$out = [];
		foreach ($list as $s) {
			$m = $found[$s['id']] ?? null;
			if ($m !== null) {
				$out[] = self::account($account) + ['id' => (string)$s['id'], 'subject' => (string)$m['subject'], 'from' => $m['from'], 'until' => $s['until']];
			}
		}
		return $out;
	}

	private static function account(Account $account): array {
		return ['accountId' => $account->getId(), 'account' => $account->getEmail()];
	}
}
