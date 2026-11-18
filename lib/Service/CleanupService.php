<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Exception\MailException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/** « Nettoyage automatique » of the folders, each night (CleanupJob). */
class CleanupService {
	public function __construct(
		private AccountMapper $mapper,
		private AccountService $accounts,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/** @return array<string, int> */
	public function rules(Account $account): array {
		return CleanupRules::decode($this->config->getUserValue($account->getUserId(), Application::APP_ID, CleanupRules::PREF . $account->getId(), '{}'));
	}

	/** @return array<string, int> */
	public function set(Account $account, string $folderId, int $days): array {
		try {
			$rules = CleanupRules::set($this->rules($account), $folderId, $days);
		} catch (\InvalidArgumentException $e) {
			throw $e->getMessage() === 'too many'
				? new MailException('Trop de dossiers nettoyés automatiquement (%s au plus)', [CleanupRules::MAX_FOLDERS])
				: new MailException('Durée invalide');
		}
		$this->save($account, $rules);
		return $rules;
	}

	private function save(Account $account, array $rules): void {
		if ($rules === []) {
			$this->config->deleteUserValue($account->getUserId(), Application::APP_ID, CleanupRules::PREF . $account->getId());
		} else {
			$this->config->setUserValue($account->getUserId(), Application::APP_ID, CleanupRules::PREF . $account->getId(), (string)json_encode($rules));
		}
	}

	/** Every night: the folders of everyone who asked. @return int messages deleted */
	public function cleanAll(?\DateTimeImmutable $now = null): int {
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$done = 0;
		foreach ($this->mapper->findAll() as $account) {
			if ($this->rules($account) === []) {
				continue;
			}
			try {
				$done += $this->clean($account, $now);
			} catch (\Throwable $e) {
				$this->logger->warning('Webmail: folders of ' . $account->getEmail() . ' not cleaned: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
		return $done;
	}

	/** The folders of one account (a few hundred messages a night). @return int deleted */
	public function clean(Account $account, \DateTimeImmutable $now): int {
		$client = $this->accounts->getClient($account, AccountService::BACKGROUND_TIMEOUT);
		$roles = [];
		foreach ($client->listFolders() as $f) {
			$roles[(string)$f['id']] = (string)($f['role'] ?? '');
		}
		$rules = $this->rules($account);
		// a folder gone (deleted, or Exchange's own renamed): its rule too
		$kept = array_filter($rules, fn ($folder) => isset($roles[$folder]) && !in_array($roles[$folder], CleanupRules::NEVER, true), ARRAY_FILTER_USE_KEY);
		if ($kept !== $rules) {
			$this->save($account, $kept);
		}
		$pinned = PinList::decode($this->config->getUserValue($account->getUserId(), Application::APP_ID, 'pinned_' . $account->getId(), '[]'));
		$deleted = 0;
		foreach ($kept as $folder => $days) {
			$cutoff = CleanupRules::cutoff($days, $now);
			$offset = 0;
			while ($deleted < CleanupRules::PER_NIGHT) {
				$page = $client->findItems($folder, $offset, 100, null, 'date', false);
				$found = CleanupRules::deletable($page['items'], $cutoff, $pinned);
				$ids = array_slice($found['ids'], 0, CleanupRules::PER_NIGHT - $deleted);
				if ($ids !== []) {
					$client->deleteItems($ids, $roles[$folder] === 'deleteditems');
					$deleted += count($ids);
				}
				if ($found['done'] || !$page['hasMore']) {
					break;
				}
				$offset += count($page['items']) - count($ids);
			}
		}
		return $deleted;
	}
}
