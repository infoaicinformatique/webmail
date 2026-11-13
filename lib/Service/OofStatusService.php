<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Ews\EwsOof;
use OCA\Webmail\Exception\MailException;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\UserStatus\IManager as IUserStatusManager;
use OCP\UserStatus\IUserStatus;
use Psr\Log\LoggerInterface;

/**
 * The automatic reply of Exchange (« absent du bureau ») shown in the status of Nextcloud:
 * « En vacances » (away) while it is active, the status from before back afterwards.
 * Kept up to date when it is changed here, and every hour (changed in Outlook, dates reached).
 */
class OofStatusService {
	public function __construct(
		private AccountMapper $mapper,
		private AccountService $accounts,
		private IConfig $config,
		private IUserStatusManager $statuses,
		private IUserManager $users,
		private LoggerInterface $logger,
	) {
	}

	public function isEnabled(string $userId, int $accountId): bool {
		return $this->config->getUserValue($userId, Application::APP_ID, 'oof_status_' . $accountId, '1') === '1';
	}

	public function setEnabled(string $userId, int $accountId, bool $enabled): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'oof_status_' . $accountId, $enabled ? '1' : '0');
	}

	/**
	 * The status after the automatic reply of one account: set while it is active, the one
	 * from before back when no account of the user is away any more.
	 */
	public function apply(Account $account, bool $active): void {
		$userId = $account->getUserId();
		$key = 'oof_status_on_' . $account->getId();
		$wasOn = $this->config->getUserValue($userId, Application::APP_ID, $key, '') === '1';
		$on = $active && $this->isEnabled($userId, $account->getId());
		if ($on === $wasOn) {
			return;
		}
		if ($on) {
			// (a backup of the current status: it comes back with revertUserStatus)
			$this->statuses->setUserStatus($userId, IUserStatus::MESSAGE_VACATION, IUserStatus::AWAY, true);
			$this->config->setUserValue($userId, Application::APP_ID, $key, '1');
			return;
		}
		$this->config->deleteUserValue($userId, Application::APP_ID, $key);
		foreach ($this->mapper->findAll() as $other) {
			if ($other->getUserId() === $userId && $other->getId() !== $account->getId()
				&& $this->config->getUserValue($userId, Application::APP_ID, 'oof_status_on_' . $other->getId(), '') === '1') {
				return; // still away with another account
			}
		}
		$this->statuses->revertUserStatus($userId, IUserStatus::MESSAGE_VACATION, IUserStatus::AWAY);
	}

	/** Every hour: the automatic replies of the accounts with the status kept up to date. */
	public function syncAll(): void {
		foreach ($this->mapper->findAll() as $account) {
			$userId = $account->getUserId();
			$on = $this->config->getUserValue($userId, Application::APP_ID, 'oof_status_on_' . $account->getId(), '') === '1';
			if (!$this->isEnabled($userId, $account->getId()) && !$on) {
				continue;
			}
			$user = $this->users->get($userId);
			if ($user === null || !$user->isEnabled()) {
				continue;
			}
			try {
				$oof = (new EwsOof($this->accounts->getClient($account, AccountService::BACKGROUND_TIMEOUT), $account->getEmail()))->get();
				$this->apply($account, (bool)$oof['active']);
			} catch (MailException $e) {
				$this->logger->info('Webmail: automatic reply of ' . $account->getEmail() . ' not checked: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			} catch (\Throwable $e) {
				$this->logger->warning('Webmail: automatic reply of ' . $account->getEmail() . ' not checked', ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
	}
}
