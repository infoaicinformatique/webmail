<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Controller\MailController;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * « Classer les pièces jointes dans Fichiers »: as messages arrive, the attachments of the
 * senders chosen are put into their folder of Files (a background job, webmail closed or not).
 * Only the messages arrived after the first rule are looked at: nothing old is imported.
 */
class AutoFileService {
	/** Biggest attachment filed (larger ones stay in the message). */
	public const MAX_BYTES = 50 * 1024 * 1024;

	public function __construct(
		private AccountMapper $mapper,
		private AccountService $accounts,
		private IRootFolder $rootFolder,
		private IConfig $config,
		private IUserManager $users,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/** @return list<array{from: string, folder: string}> */
	public function rules(Account $account): array {
		return AttachmentRules::decode($this->config->getUserValue($account->getUserId(), Application::APP_ID, 'autofile_' . $account->getId(), '[]'));
	}

	/** @param list<array{from: string, folder: string}> $rules */
	public function save(Account $account, array $rules): array {
		$uid = $account->getUserId();
		if ($rules === []) {
			$this->config->deleteUserValue($uid, Application::APP_ID, 'autofile_' . $account->getId());
			$this->config->deleteUserValue($uid, Application::APP_ID, 'autofile_since_' . $account->getId());
			return [];
		}
		$this->config->setUserValue($uid, Application::APP_ID, 'autofile_' . $account->getId(), (string)json_encode($rules));
		// from now on (the messages already there stay as they are)
		if ($this->config->getUserValue($uid, Application::APP_ID, 'autofile_since_' . $account->getId(), '') === '') {
			$this->config->setUserValue($uid, Application::APP_ID, 'autofile_since_' . $account->getId(), gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime() - 60));
		}
		return $rules;
	}

	/** @return int files put into Files */
	public function runAll(): int {
		$filed = 0;
		foreach ($this->mapper->findAll() as $account) {
			$rules = $this->rules($account);
			if ($rules === []) {
				continue;
			}
			$user = $this->users->get($account->getUserId());
			if ($user === null || !$user->isEnabled()) {
				continue;
			}
			try {
				$filed += $this->run($account, $rules);
			} catch (MailException $e) {
				$this->logger->info('Webmail: attachments of ' . $account->getEmail() . ' not filed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			} catch (\Throwable $e) {
				$this->logger->warning('Webmail: attachments of ' . $account->getEmail() . ' not filed', ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
		return $filed;
	}

	/** @param list<array{from: string, folder: string}> $rules */
	private function run(Account $account, array $rules): int {
		$uid = $account->getUserId();
		$key = 'autofile_since_' . $account->getId();
		$since = $this->config->getUserValue($uid, Application::APP_ID, $key, '') ?: gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime() - 60);
		$client = $this->accounts->getClient($account, AccountService::BACKGROUND_TIMEOUT);
		$filed = 0;
		foreach ($client->withAttachmentsSince($since) as $m) {
			$folder = AttachmentRules::folderFor($rules, (string)($m['from']['email'] ?? ''));
			if ($folder !== null) {
				$item = $client->getItem($m['id']);
				foreach ($item['attachments'] as $a) {
					// (pictures inside the text, attached e-mails, too big: not files to keep)
					if ($a['isInline'] || $a['isItem'] || $a['size'] > self::MAX_BYTES) {
						continue;
					}
					$att = $client->getAttachment((string)$a['id']);
					$this->put($uid, $folder, $att['name'], $att['content']);
					$filed++;
				}
			}
			// done up to this message: a failure later starts again after it
			if (!empty($m['date'])) {
				$this->config->setUserValue($uid, Application::APP_ID, $key, (string)$m['date']);
			}
		}
		return $filed;
	}

	private function put(string $uid, string $path, string $name, string $content): void {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		try {
			$folder = $userFolder->get($path);
		} catch (NotFoundException) {
			$folder = $userFolder->newFolder($path);
		}
		if (!$folder instanceof Folder || !$folder->isCreatable()) {
			throw new MailException('Dossier non accessible en écriture : %s', [$path]);
		}
		$folder->newFile($folder->getNonExistingName(MailController::safeFileName($name)), $content);
	}
}
