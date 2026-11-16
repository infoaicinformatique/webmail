<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Ews\EwsClient;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * « Archiver » in one click (the folder « Archives » made when missing), and the
 * nightly archiving of the old read messages of the inbox, when asked.
 */
class ArchiveService {
	public function __construct(
		private AccountService $accounts,
		private AccountMapper $mapper,
		private IConfig $config,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	private function key(Account $account): string {
		return 'autoarchive_' . $account->getId();
	}

	/** months after which the read messages leave the inbox (0: never) */
	public function months(Account $account): int {
		return ArchiveRules::months((int)$this->config->getUserValue($account->getUserId(), Application::APP_ID, $this->key($account), '0'));
	}

	public function setMonths(Account $account, int $months): int {
		$months = ArchiveRules::months($months);
		if ($months === 0) {
			$this->config->deleteUserValue($account->getUserId(), Application::APP_ID, $this->key($account));
		} else {
			$this->config->setUserValue($account->getUserId(), Application::APP_ID, $this->key($account), (string)$months);
		}
		return $months;
	}

	/** The archive of the mailbox, made at its top when there is none. */
	public function folder(EwsClient $client): string {
		$id = ArchiveRules::archiveFolder($client->listFolders());
		return $id ?? $client->createFolder($this->l->t('Archives'));
	}

	/**
	 * @param string[] $itemIds
	 * @return array{folderId: string, ids: list<?string>}
	 */
	public function archive(Account $account, array $itemIds): array {
		$client = $this->accounts->getClient($account);
		$folder = $this->folder($client);
		return ['folderId' => $folder, 'ids' => $client->moveItems(array_values($itemIds), $folder)];
	}

	/** Every night: the old read messages of the inboxes whose owner asked for it. */
	public function archiveAll(?\DateTimeImmutable $now = null): int {
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$done = 0;
		foreach ($this->mapper->findAll() as $account) {
			$months = $this->months($account);
			if ($months === 0) {
				continue;
			}
			try {
				$done += $this->archiveOld($account, ArchiveRules::cutoff($months, $now));
			} catch (\Throwable $e) {
				$this->logger->warning('Webmail: old messages of ' . $account->getEmail() . ' not archived: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
		return $done;
	}

	/** The read messages of the inbox received before $cutoff, to the archive (oldest first, a few hundred a night). */
	public function archiveOld(Account $account, \DateTimeImmutable $cutoff): int {
		$client = $this->accounts->getClient($account, AccountService::BACKGROUND_TIMEOUT);
		$pinned = PinList::decode($this->config->getUserValue($account->getUserId(), Application::APP_ID, 'pinned_' . $account->getId(), '[]'));
		$folder = null;
		$moved = 0;
		$offset = 0;
		while ($moved < ArchiveRules::PER_NIGHT) {
			$page = $client->findItems('inbox', $offset, 100, null, 'date', false);
			$found = ArchiveRules::archivable($page['items'], $cutoff, $pinned);
			$ids = array_slice($found['ids'], 0, ArchiveRules::PER_NIGHT - $moved);
			if ($ids !== []) {
				$folder ??= $this->folder($client);
				$client->moveItems($ids, $folder);
				$moved += count($ids);
			}
			if ($found['done'] || !$page['hasMore']) {
				break;
			}
			// the messages moved left the inbox: the next ones are now at the same place
			$offset += count($page['items']) - count($ids);
		}
		return $moved;
	}
}
