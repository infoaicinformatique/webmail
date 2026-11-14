<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Exception\MailException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * « Me rappeler si pas de réponse »: a message sent waits for an answer; at the time chosen,
 * if none of the people written to answered (a message from them in the inbox with the
 * subject), the bell of Nextcloud says so. Checked by a background job, webmail closed or not.
 */
class FollowUpService {
	public function __construct(
		private AccountMapper $mapper,
		private AccountService $accounts,
		private IConfig $config,
		private IManager $notifications,
		private IUserManager $users,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	private function key(Account $account): string {
		return 'followup_' . $account->getId();
	}

	/** @return list<array{subject: string, to: list<string>, since: string, until: string}> */
	public function list(Account $account): array {
		return FollowUpList::decode($this->config->getUserValue($account->getUserId(), Application::APP_ID, $this->key($account), '[]'));
	}

	/** @param list<array<string, mixed>> $list */
	private function save(Account $account, array $list): void {
		if ($list === []) {
			$this->config->deleteUserValue($account->getUserId(), Application::APP_ID, $this->key($account));
		} else {
			$this->config->setUserValue($account->getUserId(), Application::APP_ID, $this->key($account), (string)json_encode($list));
		}
	}

	/**
	 * A message just sent, waited for $days days.
	 * @return array{until: string}
	 * @throws MailException
	 */
	public function add(Account $account, string $subject, string $to, int $days): array {
		$list = FollowUpList::add($this->list($account), $subject, $to, $days, $this->time->getTime());
		$this->save($account, $list);
		return ['until' => $list[count($list) - 1]['until']];
	}

	/** @return int notifications sent */
	public function checkAll(): int {
		$sent = 0;
		foreach ($this->mapper->findAll() as $account) {
			$list = $this->list($account);
			if ($list === []) {
				continue;
			}
			$user = $this->users->get($account->getUserId());
			if ($user === null || !$user->isEnabled()) {
				continue;
			}
			try {
				$sent += $this->check($account, $list);
			} catch (MailException $e) {
				// wrong password, server down…: next time
				$this->logger->info('Webmail: answers awaited by ' . $account->getEmail() . ' not checked: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			} catch (\Throwable $e) {
				$this->logger->warning('Webmail: answers awaited by ' . $account->getEmail() . ' not checked', ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
		return $sent;
	}

	/** @param list<array{subject: string, to: list<string>, since: string, until: string}> $list */
	private function check(Account $account, array $list): int {
		[$due] = FollowUpList::due($list, $this->time->getTime());
		if ($due === []) {
			return 0;
		}
		$client = $this->accounts->getClient($account, AccountService::BACKGROUND_TIMEOUT);
		$sent = 0;
		$done = [];
		foreach ($due as $e) {
			$received = $client->receivedSince($e['since'], $e['subject']);
			if (!FollowUpList::answered($received, $e['subject'], $e['to'])) {
				$n = $this->notifications->createNotification();
				$n->setApp(Application::APP_ID)
					->setUser($account->getUserId())
					->setDateTime(new \DateTime('@' . $this->time->getTime()))
					->setObject('followup', hash('sha256', $account->getId() . '|' . $e['subject'] . '|' . $e['since']))
					->setSubject('followup', [
						'account' => $account->getId(),
						'email' => $account->getEmail(),
						'subject' => $e['subject'],
						'to' => implode(', ', $e['to']),
						'since' => $e['since'],
					]);
				$this->notifications->notify($n);
				$sent++;
			}
			$done[] = $e;
		}
		// saved again from the latest list: what was added meanwhile is kept
		$latest = $this->list($account);
		$this->save($account, array_values(array_filter($latest, fn ($x) => !in_array($x, $done, true))));
		return $sent;
	}
}
