<?php

declare(strict_types=1);

namespace OCA\Webmail\Calendar;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsTasks;
use OCA\Webmail\Service\AccountService;
use OCP\Calendar\ICalendarProvider;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * The Exchange calendars the user chose to see in the Calendar app of
 * Nextcloud (« Visible dans l'Agenda de Nextcloud », in the webmail), and
 * the Exchange task lists in the Tasks app (« Visible dans Tâches de Nextcloud »).
 */
class ExchangeCalendarProvider implements ICalendarProvider {
	public function __construct(
		private AccountService $accounts,
		private IConfig $config,
		private ICacheFactory $caches,
		private IURLGenerator $url,
		private LoggerInterface $logger,
	) {
	}

	public static function settingKey(int $accountId): string {
		return 'nc_calendar_' . $accountId;
	}

	public function getCalendars(string $principalUri, array $calendarUris = []): array {
		if (!str_starts_with($principalUri, 'principals/users/')) {
			return [];
		}
		$userId = substr($principalUri, strlen('principals/users/'));
		$calendars = [];
		$tz = null;
		foreach ($this->accounts->findAll($userId) as $account) {
			$uri = ExchangeCalendar::uriFor($account->getId());
			if ($this->config->getUserValue($userId, Application::APP_ID, self::settingKey($account->getId()), '') !== '1'
				|| ($calendarUris !== [] && !in_array($uri, $calendarUris, true))) {
				continue;
			}
			if ($tz === null) {
				try {
					$tz = new \DateTimeZone($this->config->getUserValue($userId, 'core', 'timezone', '') ?: date_default_timezone_get());
				} catch (\Exception $e) {
					$tz = new \DateTimeZone('UTC');
				}
			}
			$link = $this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query(['account' => $account->getId(), 'module' => 'calendar']) . '&event=';
			$calendars[] = new ExchangeCalendar($account, $this->accounts, $this->caches->createDistributed('webmail-calendar'), $tz, $link, $this->logger);
		}
		return array_merge($calendars, $this->taskLists($userId, $calendarUris));
	}

	/** @return list<ExchangeTasksCalendar> the Exchange task lists shown in the Tasks app */
	private function taskLists(string $userId, array $calendarUris): array {
		$out = [];
		$cache = $this->caches->createDistributed('webmail-calendar');
		foreach ($this->accounts->findAll($userId) as $account) {
			$id = $account->getId();
			if ($this->config->getUserValue($userId, Application::APP_ID, ExchangeTasksCalendar::settingKey($id), '') !== '1') {
				continue;
			}
			// the lists of the mailbox: asked to Exchange at most every 10 minutes
			$key = 'tasklists-' . $id . '-' . md5($userId);
			$lists = $cache->get($key);
			if (!is_array($lists)) {
				try {
					$lists = (new EwsTasks($this->accounts->getClient($account, AccountService::BACKGROUND_TIMEOUT)))->lists();
					$cache->set($key, $lists, 600);
				} catch (\Throwable $e) {
					$this->logger->info('Webmail: Exchange task lists of ' . $account->getEmail() . ' not read: ' . $e->getMessage(), ['app' => Application::APP_ID]);
					$lists = [['key' => 'tasks', 'name' => 'Tâches', 'default' => true]];
				}
			}
			$link = $this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query(['account' => $id, 'module' => 'tasks']) . '&task=';
			foreach ($lists as $l) {
				$uri = ExchangeTasksCalendar::uriFor($id, (string)$l['key']);
				if ($calendarUris !== [] && !in_array($uri, $calendarUris, true)) {
					continue;
				}
				$out[] = new ExchangeTasksCalendar($account, (string)$l['key'], (string)$l['name'], $this->accounts, $cache, $link, $this->logger);
			}
		}
		return $out;
	}
}
