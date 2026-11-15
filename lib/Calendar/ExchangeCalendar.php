<?php

declare(strict_types=1);

namespace OCA\Webmail\Calendar;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\ExchangeIcs;
use OCP\Calendar\ICalendar;
use OCP\Constants;
use OCP\ICache;
use Psr\Log\LoggerInterface;

/**
 * The Exchange calendar of an account, read only, in the Calendar app of
 * Nextcloud (and the phones synced with it): Nextcloud shows it as
 * « Exchange — user@… » beside its own calendars. The events of the last
 * month and of the next year, asked to Exchange at most every 5 minutes.
 */
class ExchangeCalendar implements ICalendar {
	/** how long the events are kept before asking Exchange again */
	public const CACHE_TTL = 300;

	/** @var ?list<\Sabre\VObject\Component> */
	private ?array $events = null;

	public function __construct(
		private Account $account,
		private AccountService $accounts,
		private ICache $cache,
		private \DateTimeZone $tz,
		private string $link,
		private LoggerInterface $logger,
	) {
	}

	public static function uriFor(int $accountId): string {
		return 'webmail-exchange-' . $accountId;
	}

	public function getKey(): string {
		return self::uriFor($this->account->getId());
	}

	public function getUri(): string {
		return self::uriFor($this->account->getId());
	}

	public function getDisplayName(): ?string {
		return 'Exchange — ' . $this->account->getEmail();
	}

	public function getDisplayColor(): ?string {
		return '#0078d4';
	}

	public function getPermissions(): int {
		return Constants::PERMISSION_READ;
	}

	public function isDeleted(): bool {
		return false;
	}

	/**
	 * The events, as the CalDAV wrapper of Nextcloud reads them (one
	 * component per event; « UID » or « X-FILENAME » to find one). Other
	 * callers expect another format: nothing for them.
	 */
	public function search(string $pattern, array $searchProperties = [], array $options = [], ?int $limit = null, ?int $offset = null): array {
		if (!self::calledByCalDav()) {
			return [];
		}
		$events = $this->events();
		if ($pattern !== '') {
			if (!in_array('UID', $searchProperties, true)) {
				// no X-FILENAME: the name of an event is its UID
				return [];
			}
			$events = array_values(array_filter($events, fn ($e) => (string)$e->UID === $pattern));
		}
		return array_slice($events, $offset ?? 0, $limit);
	}

	private static function calledByCalDav(): bool {
		foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6) as $frame) {
			if (str_starts_with((string)($frame['class'] ?? ''), 'OCA\\DAV\\CalDAV\\AppCalendar\\')) {
				return true;
			}
		}
		return false;
	}

	/** @return list<\Sabre\VObject\Component> */
	private function events(): array {
		if ($this->events !== null) {
			return $this->events;
		}
		$key = 'ics-' . $this->account->getId() . '-' . md5($this->account->getUserId());
		$ics = $this->cache->get($key);
		if (!is_string($ics)) {
			try {
				$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
				$client = $this->accounts->getClient($this->account, AccountService::BACKGROUND_TIMEOUT);
				$found = (new EwsCalendar($client))->findEvents($now->modify('-30 days'), $now->modify('+365 days'));
				$ics = ExchangeIcs::calendar($found, $this->tz, $this->link);
				$this->cache->set($key, $ics, self::CACHE_TTL);
			} catch (\Throwable $e) {
				// Exchange unreachable, password changed…: an empty calendar this time
				$this->logger->info('Webmail: Exchange calendar of ' . $this->account->getEmail() . ' not read: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				return $this->events = [];
			}
		}
		$vcal = \Sabre\VObject\Reader::read($ics);
		return $this->events = AppComponents::wrap($vcal->select('VEVENT'));
	}
}
