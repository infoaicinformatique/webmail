<?php

declare(strict_types=1);

namespace OCA\Webmail\Calendar;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AccountService;
use OCP\Calendar\ICalendarProvider;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * The Exchange calendars the user chose to see in the Calendar app of
 * Nextcloud (« Visible dans l'Agenda de Nextcloud », in the webmail).
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
		return $calendars;
	}
}
