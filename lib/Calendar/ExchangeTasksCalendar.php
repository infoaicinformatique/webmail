<?php

declare(strict_types=1);

namespace OCA\Webmail\Calendar;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Ews\EwsTasks;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\NcTaskRules;
use OCP\Calendar\ICalendar;
use OCP\Constants;
use OCP\ICache;
use Psr\Log\LoggerInterface;

/**
 * A task list of Exchange, read only, in the Tasks app of Nextcloud (and the phones
 * synced with it): « Tâches — user@… », « Chantier Nice — user@… ». Its tasks (the
 * completed ones too) are asked to Exchange at most every 5 minutes; each one links
 * back to the webmail, where it is changed.
 */
class ExchangeTasksCalendar implements ICalendar {
	public const CACHE_TTL = 300;

	/** @var ?list<\Sabre\VObject\Component> */
	private ?array $todos = null;

	public function __construct(
		private Account $account,
		private string $list,
		private string $name,
		private AccountService $accounts,
		private ICache $cache,
		private string $link,
		private LoggerInterface $logger,
	) {
	}

	public static function settingKey(int $accountId): string {
		return 'nc_tasks_' . $accountId;
	}

	/** « webmail-exchange-tasks-3 » for « Tâches », « webmail-exchange-tasks-3-1a2b3c4d » for another list. */
	public static function uriFor(int $accountId, string $list): string {
		return 'webmail-exchange-tasks-' . $accountId . ($list === 'tasks' ? '' : '-' . substr(sha1($list), 0, 8));
	}

	public function getKey(): string {
		return self::uriFor($this->account->getId(), $this->list);
	}

	public function getUri(): string {
		return $this->getKey();
	}

	public function getDisplayName(): ?string {
		return $this->name . ' — ' . $this->account->getEmail();
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

	/** The tasks, as the CalDAV wrapper of Nextcloud reads them (see ExchangeCalendar::search). */
	public function search(string $pattern, array $searchProperties = [], array $options = [], ?int $limit = null, ?int $offset = null): array {
		if (!self::calledByCalDav()) {
			return [];
		}
		$todos = $this->todos();
		if ($pattern !== '') {
			if (!in_array('UID', $searchProperties, true)) {
				return [];
			}
			$todos = array_values(array_filter($todos, fn ($t) => (string)$t->UID === $pattern));
		}
		return array_slice($todos, $offset ?? 0, $limit);
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
	private function todos(): array {
		if ($this->todos !== null) {
			return $this->todos;
		}
		$key = 'todo-' . $this->getKey() . '-' . md5($this->account->getUserId());
		$ics = $this->cache->get($key);
		if (!is_string($ics)) {
			try {
				$client = $this->accounts->getClient($this->account, AccountService::BACKGROUND_TIMEOUT);
				$ics = NcTaskRules::calendar((new EwsTasks($client))->list(true, $this->list), $this->link);
				$this->cache->set($key, $ics, self::CACHE_TTL);
			} catch (\Throwable $e) {
				$this->logger->info('Webmail: Exchange tasks of ' . $this->account->getEmail() . ' not read: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				return $this->todos = [];
			}
		}
		return $this->todos = array_values(\Sabre\VObject\Reader::read($ics)->select('VTODO'));
	}
}
