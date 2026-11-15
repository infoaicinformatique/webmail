<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * The new versions of the app, read from its releases (kept a few hours), shown to the
 * administrators in the settings and told once in their notifications.
 */
class UpdateService {
	public const RELEASES = 'https://api.github.com/repos/infoaicinformatique/webmail/releases?per_page=20';
	private const TTL = 6 * 3600;

	public function __construct(
		private IClientService $http,
		private ICacheFactory $cacheFactory,
		private IConfig $config,
		private IAppManager $appManager,
		private IGroupManager $groups,
		private INotificationManager $notifications,
		private LoggerInterface $logger,
	) {
	}

	public function installed(): string {
		return (string)$this->appManager->getAppVersion(Application::APP_ID);
	}

	/**
	 * @return array{installed: string, latest: string, available: bool, releases: list<array<string, string>>, checked: string, error?: string}
	 */
	public function check(bool $fresh = false): array {
		$installed = $this->installed();
		$cache = $this->cacheFactory->createDistributed('webmail-update');
		if ($fresh) {
			$cache->remove('releases');
		}
		$releases = $fresh ? null : $cache->get('releases');
		$error = null;
		if (!is_array($releases)) {
			try {
				$releases = $this->fetch();
				$cache->set('releases', $releases, self::TTL);
				$this->config->setAppValue(Application::APP_ID, 'update_checked', (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
			} catch (\Throwable $e) {
				$this->logger->info('Webmail: the new versions could not be read: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				$releases = [];
				$error = $e->getMessage();
			}
		}
		$newer = UpdateCheck::newer($installed, $releases);
		$out = [
			'installed' => $installed,
			'latest' => $newer[0]['version'] ?? $installed,
			'available' => $newer !== [],
			'releases' => $newer,
			'checked' => $this->config->getAppValue(Application::APP_ID, 'update_checked', ''),
		];
		if ($error !== null) {
			$out['error'] = $error;
		}
		return $out;
	}

	/** @return array<int, mixed> */
	private function fetch(): array {
		$url = $this->config->getAppValue(Application::APP_ID, 'update_url', '') ?: self::RELEASES;
		$r = $this->http->newClient()->get($url, [
			'timeout' => 10,
			'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'nextcloud-webmail'],
			'verify' => $this->config->getAppValue(Application::APP_ID, 'verify_tls', 'yes') !== 'no',
		]);
		$data = json_decode((string)$r->getBody(), true);
		if (!is_array($data)) {
			throw new \RuntimeException('unexpected answer');
		}
		return $data;
	}

	/**
	 * A new version told once to the administrators, in their notifications (the daily job).
	 * Nothing when the check is turned off (« update_notify » = no).
	 */
	public function notifyAdmins(): void {
		if ($this->config->getAppValue(Application::APP_ID, 'update_notify', 'yes') === 'no') {
			return;
		}
		$state = $this->check();
		if (!$state['available'] || $this->config->getAppValue(Application::APP_ID, 'update_notified', '') === $state['latest']) {
			return;
		}
		$admin = $this->groups->get('admin');
		if ($admin === null) {
			return;
		}
		foreach ($admin->getUsers() as $user) {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($user->getUID())
				->setDateTime(new \DateTime())
				->setObject('update', $state['latest'])
				->setSubject('update_available', ['version' => $state['latest'], 'installed' => $state['installed']]);
			$this->notifications->notify($n);
		}
		$this->config->setAppValue(Application::APP_ID, 'update_notified', $state['latest']);
	}

	/** The notification of a version told, taken away once it (or a newer one) is installed. */
	public function dismissInstalled(): void {
		$told = $this->config->getAppValue(Application::APP_ID, 'update_notified', '');
		if ($told === '' || version_compare($told, $this->installed(), '>')) {
			return;
		}
		$n = $this->notifications->createNotification();
		$n->setApp(Application::APP_ID)->setObject('update', $told);
		$this->notifications->markProcessed($n);
	}
}
