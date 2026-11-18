<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Exception\MailException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * « Sauvegarde dans Fichiers » of an account: the settings, and the run of the background job
 * (also « Sauvegarder maintenant »).
 */
class BackupService {
	/** messages read from Exchange in one request */
	private const BATCH = 10;

	public function __construct(
		private AccountMapper $mapper,
		private AccountService $accounts,
		private IRootFolder $rootFolder,
		private IConfig $config,
		private IUserManager $users,
		private LoggerInterface $logger,
	) {
	}

	/** @return array<string, mixed> */
	public function settings(Account $account): array {
		return Backup::decode($this->config->getUserValue($account->getUserId(), Application::APP_ID, Backup::PREF . $account->getId(), '{}'));
	}

	private function keep(Account $account, array $s): void {
		$this->config->setUserValue($account->getUserId(), Application::APP_ID, Backup::PREF . $account->getId(), (string)json_encode($s));
	}

	/**
	 * Turned on (from now, or some days before), off, or changed.
	 * @param list<string> $folders
	 * @return array<string, mixed>
	 */
	public function save(Account $account, bool $on, string $path, array $folders, int $fromDays, ?\DateTimeImmutable $now = null): array {
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$path = Backup::normalizePath($path);
		if (!Backup::validPath($path)) {
			throw new MailException('Dossier de sauvegarde invalide : %s', [$path]);
		}
		$folders = array_values(array_intersect(array_keys(Backup::FOLDERS), $folders));
		if ($on && $folders === []) {
			throw new MailException('Choisissez au moins un dossier à sauvegarder');
		}
		$s = $this->settings($account);
		$s['path'] = $path;
		if ($folders !== []) {
			$s['folders'] = $folders;
		}
		// turned on: each folder from the start chosen (a folder already followed goes on where it was)
		if ($on && !$s['on']) {
			$s['cursors'] = [];
		}
		foreach ($s['folders'] as $f) {
			$s['cursors'][$f] ??= ['at' => Backup::start($now, $fromDays), 'ids' => []];
		}
		$s['on'] = $on;
		$this->keep($account, $s);
		return $s;
	}

	/** @return int messages saved */
	public function runAll(): int {
		$saved = 0;
		foreach ($this->mapper->findAll() as $account) {
			if (!$this->settings($account)['on']) {
				continue;
			}
			$user = $this->users->get($account->getUserId());
			if ($user === null || !$user->isEnabled()) {
				continue;
			}
			try {
				$saved += $this->run($account, AccountService::BACKGROUND_TIMEOUT);
			} catch (\Throwable $e) {
				$this->logger->info('Webmail: backup of ' . $account->getEmail() . ' stopped: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
		return $saved;
	}

	/** The new messages saved, the place reached kept after each batch. @return int saved */
	public function run(Account $account, ?int $timeout = null): int {
		$s = $this->settings($account);
		if (!$s['on']) {
			return 0;
		}
		$uid = $account->getUserId();
		$zone = $this->zone($uid);
		$saved = 0;
		$error = '';
		try {
			$client = $this->accounts->getClient($account, $timeout);
			$left = Backup::PER_RUN;
			foreach ($s['folders'] as $f) {
				$cursor = $s['cursors'][$f] ?? ['at' => Backup::start(new \DateTimeImmutable(), 0), 'ids' => []];
				$todo = Backup::pending($client->itemsSince($f, $cursor['at']), $cursor, $left);
				foreach (array_chunk($todo, self::BATCH) as $batch) {
					$byId = array_column($batch, null, 'id');
					foreach ($client->mimes(array_column($batch, 'id')) as $m) {
						$item = $byId[$m['id']] ?? null;
						if ($item === null) {
							continue;
						}
						$this->put($uid, Backup::folderFor($s['path'], $account->getEmail(), $f, $item['date'], $zone),
							FolderExport::fileName($m['subject'], $item['date'], $zone), $m['content']);
						$saved++;
					}
					// (a message gone meanwhile is passed too)
					foreach ($batch as $item) {
						$cursor = Backup::advance($cursor, $item['id'], $item['date']);
					}
					$s['cursors'][$f] = $cursor;
					$this->keep($account, $s);
				}
				$left -= count($todo);
				if ($left <= 0) {
					break;
				}
			}
		} catch (MailException $e) {
			$error = $e->getMessage();
		} catch (\OCP\Files\NotPermittedException | \OCP\Files\InvalidPathException $e) {
			$error = 'Dossier non accessible en écriture : ' . $s['path'];
		} finally {
			$s['total'] += $saved;
			$s['last'] = ['at' => gmdate('Y-m-d\TH:i:s\Z'), 'saved' => $saved, 'error' => $error];
			$this->keep($account, $s);
		}
		if ($error !== '') {
			throw new MailException('Sauvegarde interrompue : %s', [$error]);
		}
		return $saved;
	}

	private function zone(string $uid): \DateTimeZone {
		try {
			return new \DateTimeZone($this->config->getUserValue($uid, 'core', 'timezone', '') ?: date_default_timezone_get());
		} catch (\Exception) {
			return new \DateTimeZone('UTC');
		}
	}

	private function put(string $uid, string $path, string $name, string $content): void {
		$folder = $this->rootFolder->getUserFolder($uid);
		// each level made if missing
		foreach (explode('/', $path) as $part) {
			try {
				$next = $folder->get($part);
			} catch (NotFoundException) {
				$next = $folder->newFolder($part);
			}
			if (!$next instanceof Folder) {
				throw new MailException('Dossier non accessible en écriture : %s', [$path]);
			}
			$folder = $next;
		}
		if (!$folder->isCreatable()) {
			throw new MailException('Dossier non accessible en écriture : %s', [$path]);
		}
		$folder->newFile($folder->getNonExistingName($name), $content);
	}
}
