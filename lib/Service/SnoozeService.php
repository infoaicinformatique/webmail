<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Exception\MailException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * « Reporter » (snooze): a message leaves the inbox for the « Reportés » folder, and a
 * background job puts it back in the inbox, unread, at the time chosen — even with no
 * webmail open. The times are kept in the user's preferences (Exchange has no such thing).
 */
class SnoozeService {
	public function __construct(
		private AccountMapper $mapper,
		private AccountService $accounts,
		private IConfig $config,
		private IUserManager $users,
		private ITimeFactory $time,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/** @return list<array{id: string, until: string}> */
	public function list(string $userId, int $accountId): array {
		return SnoozeList::decode($this->config->getUserValue($userId, Application::APP_ID, 'snooze_' . $accountId, '[]'));
	}

	/** @param list<array{id: string, until: string}> $list */
	private function save(string $userId, int $accountId, array $list): void {
		if ($list === []) {
			$this->config->deleteUserValue($userId, Application::APP_ID, 'snooze_' . $accountId);
		} else {
			$this->config->setUserValue($userId, Application::APP_ID, 'snooze_' . $accountId, (string)json_encode($list));
		}
	}

	/**
	 * Messages put aside until $until.
	 *
	 * @param string[] $itemIds
	 * @return array{until: string, ids: list<string>} the time (UTC) and the ids in « Reportés »
	 * @throws MailException
	 */
	public function snooze(Account $account, array $itemIds, string $until): array {
		$until = SnoozeList::until($until, $this->time->getTime());
		$itemIds = array_values(array_unique(array_filter($itemIds, fn ($x) => is_string($x) && $x !== '')));
		if ($itemIds === []) {
			throw new MailException('Aucun message');
		}
		$client = $this->accounts->getClient($account);
		$folder = $this->folder($client, $account);
		$moved = $client->moveItems($itemIds, $folder);
		$ids = [];
		foreach ($itemIds as $i => $old) {
			$ids[] = $moved[$i] ?? $old;
		}
		$list = SnoozeList::add($this->list($account->getUserId(), $account->getId()), $ids, $until);
		$this->save($account->getUserId(), $account->getId(), $list);
		return ['until' => $until, 'ids' => $ids];
	}

	/**
	 * A message back in the inbox now (« Remettre dans la boîte de réception »).
	 *
	 * @throws MailException
	 */
	public function wakeNow(Account $account, string $itemId): void {
		$client = $this->accounts->getClient($account);
		$client->moveItems([$itemId], 'inbox');
		$this->save($account->getUserId(), $account->getId(),
			SnoozeList::without($this->list($account->getUserId(), $account->getId()), [$itemId]));
	}

	/** The « Reportés » folder at the top of the mailbox, made the first time. */
	private function folder(EwsClient $client, Account $account): string {
		$key = 'snooze_folder_' . $account->getId();
		$known = $this->config->getUserValue($account->getUserId(), Application::APP_ID, $key, '');
		$name = $this->l->t('Reportés');
		$id = $client->findTopFolder($name);
		if ($id === null && $known !== '' && $client->folderExists($known)) {
			// renamed by the user (or made in another language): still the one
			$id = $known;
		}
		if ($id === null) {
			$id = $client->createFolder($name);
		}
		$this->config->setUserValue($account->getUserId(), Application::APP_ID, $key, $id);
		return $id;
	}

	/** @return int messages put back in an inbox */
	public function wakeAll(): int {
		$woken = 0;
		foreach ($this->mapper->findAll() as $account) {
			$list = $this->list($account->getUserId(), $account->getId());
			if ($list === []) {
				continue;
			}
			$user = $this->users->get($account->getUserId());
			if ($user === null || !$user->isEnabled()) {
				continue;
			}
			try {
				$woken += $this->wake($account, $list);
			} catch (MailException $e) {
				// wrong password, server down…: next time
				$this->logger->info('Webmail: snoozed messages of ' . $account->getEmail() . ' not put back: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			} catch (\Throwable $e) {
				$this->logger->warning('Webmail: snoozed messages of ' . $account->getEmail() . ' not put back', ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
		return $woken;
	}

	/** @param list<array{id: string, until: string}> $list */
	private function wake(Account $account, array $list): int {
		[$due] = SnoozeList::due($list, $this->time->getTime());
		if ($due === []) {
			return 0;
		}
		$client = $this->accounts->getClient($account, AccountService::BACKGROUND_TIMEOUT);
		$folder = $this->config->getUserValue($account->getUserId(), Application::APP_ID, 'snooze_folder_' . $account->getId(), '');
		// only the messages still in « Reportés »: one moved or deleted meanwhile is left where it is
		$where = $client->parentFolders(array_column($due, 'id'));
		$back = array_values(array_filter(array_column($due, 'id'), fn ($id) => ($where[$id] ?? null) !== null && ($folder === '' || $where[$id] === $folder)));
		if ($back !== []) {
			$newIds = $client->moveItems($back, 'inbox');
			$unread = [];
			foreach ($back as $i => $old) {
				$unread[] = $newIds[$i] ?? $old;
			}
			$client->setRead($unread, false);
		}
		// saved again from the latest list: a message put aside while the job ran is kept
		$latest = $this->list($account->getUserId(), $account->getId());
		$this->save($account->getUserId(), $account->getId(), SnoozeList::without($latest, array_column($due, 'id')));
		return count($back);
	}
}
