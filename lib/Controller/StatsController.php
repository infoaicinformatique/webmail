<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\MailStats;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** « Statistiques » of a mailbox (kept 10 minutes: reading a thousand messages takes a while). */
class StatsController extends Controller {
	use ErrorHandling;

	private const TTL = 600;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private IConfig $config,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	#[NoAdminRequired]
	public function index(int $id, int $days = MailStats::DEFAULT_DAYS, bool $fresh = false): Response {
		return $this->handle(function () use ($id, $days, $fresh) {
			$days = MailStats::days($days);
			$account = $this->accounts->find((string)$this->userId, $id);
			$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '-stats');
			$key = $this->userId . '|' . $id . '|' . $days;
			$kept = $fresh ? null : $cache->get($key);
			if (is_array($kept)) {
				return $kept;
			}
			$client = $this->accounts->getClient($account);
			try {
				$tz = new \DateTimeZone($this->config->getUserValue((string)$this->userId, 'core', 'timezone', '') ?: date_default_timezone_get());
			} catch (\Exception) {
				$tz = new \DateTimeZone('UTC');
			}
			$stats = MailStats::compute($client->latestItems('inbox', MailStats::MAX), $client->latestItems('sentitems', MailStats::MAX),
				$days, $tz, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $account->getEmail());
			$cache->set($key, $stats, self::TTL);
			return $stats;
		});
	}
}
