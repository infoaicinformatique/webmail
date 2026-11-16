<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Exception\MailException;
use OCP\IConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * « Répondre plus tard »: messages received to answer, at a time chosen. Listed under
 * « À répondre » in « Aujourd'hui »; at their time, a reminder in the bell when no answer
 * has been sent (from any mail program: Exchange keeps it on the message). Answered or
 * deleted, they leave the list by themselves.
 */
class ReplyLaterService {
	public const PREF = 'replylater_';
	public const MAX = 100;

	public function __construct(
		private AccountService $accounts,
		private AccountMapper $mapper,
		private IConfig $config,
		private INotificationManager $notifications,
		private LoggerInterface $logger,
	) {
	}

	/** @return list<array{id: string, subject: string, from: ?array, until: string, told: bool}> */
	private function read(Account $account): array {
		$v = json_decode($this->config->getUserValue($account->getUserId(), Application::APP_ID, self::PREF . $account->getId(), '[]'), true);
		return is_array($v) ? array_values(array_filter($v, fn ($e) => is_array($e) && isset($e['id'], $e['until']))) : [];
	}

	private function write(Account $account, array $list): void {
		if ($list === []) {
			$this->config->deleteUserValue($account->getUserId(), Application::APP_ID, self::PREF . $account->getId());
		} else {
			$this->config->setUserValue($account->getUserId(), Application::APP_ID, self::PREF . $account->getId(), (string)json_encode(array_values($list)));
		}
	}

	/**
	 * The messages still to answer (those answered or gone taken out).
	 * @return list<array<string, mixed>> the soonest first
	 */
	public function list(Account $account): array {
		$list = $this->read($account);
		if ($list === []) {
			return [];
		}
		$replied = $this->accounts->getClient($account)->repliedTo(array_column($list, 'id'));
		$kept = array_values(array_filter($list, fn ($e) => isset($replied[$e['id']]) && !$replied[$e['id']]));
		if (count($kept) !== count($list)) {
			$this->write($account, $kept);
		}
		usort($kept, fn ($a, $b) => strcmp($a['until'], $b['until']));
		return $kept;
	}

	/** @return list<array<string, mixed>> */
	public function add(Account $account, string $itemId, string $until): array {
		try {
			$at = (new \DateTimeImmutable($until))->setTimezone(new \DateTimeZone('UTC'));
		} catch (\Exception) {
			throw new MailException('Date invalide');
		}
		$summary = $this->accounts->getClient($account)->summaries([$itemId])[0] ?? null;
		if ($summary === null) {
			throw new MailException('Message introuvable');
		}
		$list = array_values(array_filter($this->read($account), fn ($e) => $e['id'] !== $itemId));
		if (count($list) >= self::MAX) {
			throw new MailException('Trop de messages à répondre (%s au plus)', [self::MAX]);
		}
		$list[] = [
			'id' => $itemId,
			'subject' => mb_substr((string)($summary['subject'] ?? ''), 0, 200),
			'from' => $summary['from'] ?? null,
			'until' => $at->format(\DateTimeInterface::ATOM),
			'told' => false,
		];
		$this->write($account, $list);
		return $this->list($account);
	}

	/** @return list<array<string, mixed>> */
	public function remove(Account $account, string $itemId): array {
		$this->write($account, array_values(array_filter($this->read($account), fn ($e) => $e['id'] !== $itemId)));
		$this->dismiss($account, $itemId);
		return $this->list($account);
	}

	/** Every few minutes: the reminders due, for the messages still not answered. */
	public function checkAll(?\DateTimeImmutable $now = null): int {
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$told = 0;
		foreach ($this->mapper->findAll() as $account) {
			$list = $this->read($account);
			$due = array_filter($list, fn ($e) => empty($e['told']) && new \DateTimeImmutable($e['until']) <= $now);
			if ($due === []) {
				continue;
			}
			try {
				$replied = $this->accounts->getClient($account, AccountService::BACKGROUND_TIMEOUT)->repliedTo(array_column($due, 'id'));
			} catch (\Throwable $e) {
				$this->logger->info('Webmail: reply-later of ' . $account->getEmail() . ' not checked: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				continue;
			}
			$next = [];
			foreach ($list as $e) {
				if (empty($e['told']) && new \DateTimeImmutable($e['until']) <= $now) {
					if (!isset($replied[$e['id']]) || $replied[$e['id']]) {
						continue; // answered or gone: no reminder
					}
					$this->tell($account, $e);
					$e['told'] = true;
					$told++;
				}
				$next[] = $e;
			}
			$this->write($account, $next);
		}
		return $told;
	}

	private function tell(Account $account, array $e): void {
		$from = is_array($e['from'] ?? null) ? (string)(($e['from']['name'] ?? '') !== '' ? $e['from']['name'] : ($e['from']['email'] ?? '')) : '';
		$n = $this->notifications->createNotification();
		$n->setApp(Application::APP_ID)
			->setUser($account->getUserId())
			->setDateTime(new \DateTime())
			->setObject('replylater', MailNotificationService::objectId((string)$e['id']))
			->setSubject('reply_later', ['account' => $account->getId(), 'email' => $account->getEmail(), 'itemId' => (string)$e['id'], 'subject' => (string)$e['subject'], 'from' => $from]);
		$this->notifications->notify($n);
	}

	private function dismiss(Account $account, string $itemId): void {
		try {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)->setUser($account->getUserId())->setObject('replylater', MailNotificationService::objectId($itemId));
			$this->notifications->markProcessed($n);
		} catch (\Throwable) {
		}
	}

	/**
	 * For « Aujourd'hui »: the messages to answer of an account, with the time of each.
	 * @return list<array<string, mixed>>
	 */
	public function forToday(Account $account): array {
		return array_map(fn ($e) => ['id' => $e['id'], 'subject' => $e['subject'], 'from' => $e['from'], 'until' => $e['until']], $this->list($account));
	}
}
