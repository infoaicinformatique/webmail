<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Db\Account;
use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Exception\MailException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Exchange messages and events in Nextcloud's unified search (the magnifier
 * of the header): every account of the user, newest messages first,
 * upcoming events first.
 */
class UnifiedSearchService {
	/** shorter words would match half the mailbox */
	public const MIN_LENGTH = 3;

	public function __construct(
		private AccountService $accounts,
		private IConfig $config,
		private IURLGenerator $url,
		private LoggerInterface $logger,
		private IL10N $l,
	) {
	}

	/**
	 * @return array{entries: list<array{title: string, subline: string, url: string}>, next: ?int}
	 */
	public function messages(string $userId, string $term, int $offset, int $limit): array {
		$term = trim($term);
		if (mb_strlen($term) < self::MIN_LENGTH) {
			return ['entries' => [], 'next' => null];
		}
		$accounts = $this->accounts->findAll($userId);
		$lists = [];
		$more = false;
		foreach ($accounts as $account) {
			try {
				$client = $this->accounts->getClient($account);
				$r = $client->searchFolders(array_column($client->listFolders(), 'id'), 0, $offset + $limit, $term);
			} catch (\Throwable $e) {
				$this->failed($account, $e);
				continue;
			}
			$more = $more || $r['hasMore'];
			$lists[] = array_map(fn ($m) => $m + ['accountId' => $account->getId(), 'email' => $account->getEmail()], $r['items']);
		}
		[$page, $hasMore] = self::page($lists, $offset, $limit, fn ($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
		$tz = $this->timeZone($userId);
		$entries = array_map(fn ($m) => [
			'title' => trim((string)$m['subject']) !== '' ? (string)$m['subject'] : $this->l->t('(sans objet)'),
			'subline' => self::messageSubline($m, $tz, count($accounts) > 1, null, $this->l),
			'url' => $this->link(['account' => $m['accountId'], 'message' => $m['id']]),
		], $page);
		return ['entries' => $entries, 'next' => ($hasMore || $more) ? $offset + $limit : null];
	}

	/**
	 * Events of the own calendar of each account, six months back and ahead.
	 * @return array{entries: list<array{title: string, subline: string, url: string}>, next: ?int}
	 */
	public function events(string $userId, string $term, int $offset, int $limit, ?\DateTimeImmutable $now = null): array {
		$term = trim($term);
		if (mb_strlen($term) < self::MIN_LENGTH) {
			return ['entries' => [], 'next' => null];
		}
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$accounts = $this->accounts->findAll($userId);
		$lists = [];
		foreach ($accounts as $account) {
			try {
				$events = (new EwsCalendar($this->accounts->getClient($account)))->searchEvents($term, 'calendar', $now);
			} catch (\Throwable $e) {
				$this->failed($account, $e);
				continue;
			}
			$lists[] = array_map(fn ($ev) => $ev + ['accountId' => $account->getId(), 'email' => $account->getEmail()], $events);
		}
		[$page, $hasMore] = self::page($lists, $offset, $limit, self::eventOrder($now));
		$tz = $this->timeZone($userId);
		$entries = array_map(fn ($ev) => [
			'title' => trim((string)$ev['subject']) !== '' ? (string)$ev['subject'] : $this->l->t('(sans titre)'),
			'subline' => self::eventSubline($ev, $tz, $now, count($accounts) > 1, $this->l),
			'url' => $this->link(['account' => $ev['accountId'], 'module' => 'calendar', 'event' => $ev['id']]),
		], $page);
		return ['entries' => $entries, 'next' => $hasMore ? $offset + $limit : null];
	}

	private function failed(Account $account, \Throwable $e): void {
		if ($e instanceof MailException) {
			$this->logger->info('Webmail: search in ' . $account->getEmail() . ' failed: ' . $e->getMessage(), ['app' => 'webmail']);
		} else {
			$this->logger->warning('Webmail: search in ' . $account->getEmail() . ' failed', ['app' => 'webmail', 'exception' => $e]);
		}
	}

	private function timeZone(string $userId): \DateTimeZone {
		try {
			return new \DateTimeZone($this->config->getUserValue($userId, 'core', 'timezone', '') ?: date_default_timezone_get());
		} catch (\Exception) {
			return new \DateTimeZone('UTC');
		}
	}

	private function link(array $params): string {
		return $this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query($params);
	}

	// ------------------------------------------------------------------
	// Pure functions (tested without a server)
	// ------------------------------------------------------------------

	/**
	 * Several lists merged in one order, one page of it, and whether more follow.
	 * @param array<int, array<int, array<string, mixed>>> $lists
	 * @return array{0: array<int, array<string, mixed>>, 1: bool}
	 */
	public static function page(array $lists, int $offset, int $limit, callable $order): array {
		$all = $lists !== [] ? array_merge(...$lists) : [];
		usort($all, $order);
		return [array_slice($all, max(0, $offset), max(1, $limit)), count($all) > $offset + $limit];
	}

	/** Upcoming events first (soonest first), then past ones (latest first). */
	public static function eventOrder(\DateTimeImmutable $now): callable {
		$n = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
		return function (array $a, array $b) use ($n): int {
			$ea = self::utc((string)$a['end']) >= $n;
			$eb = self::utc((string)$b['end']) >= $n;
			if ($ea !== $eb) {
				return $ea ? -1 : 1;
			}
			$c = strcmp(self::utc((string)$a['start']), self::utc((string)$b['start']));
			return $ea ? $c : -$c;
		};
	}

	private static function utc(string $iso): string {
		$d = date_create_immutable($iso);
		return $d ? $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : $iso;
	}

	/** "Jean Dupont · 12 nov. 2025" (and the account when there are several) */
	public static function messageSubline(array $m, \DateTimeZone $tz, bool $withAccount, ?\DateTimeImmutable $now = null, ?IL10N $l = null): string {
		$from = $m['from'] ?? null;
		$parts = [];
		$name = is_array($from) ? trim((string)(($from['name'] ?? '') !== '' ? $from['name'] : ($from['email'] ?? ''))) : '';
		if ($name !== '') {
			$parts[] = $name;
		}
		if (!empty($m['date'])) {
			$parts[] = self::day((string)$m['date'], $tz, $now, $l);
		}
		if ($withAccount) {
			$parts[] = (string)$m['email'];
		}
		return implode(' · ', $parts);
	}

	/** "Demain 09:00 · Salle Riviera" */
	public static function eventSubline(array $ev, \DateTimeZone $tz, \DateTimeImmutable $now, bool $withAccount, ?IL10N $l = null): string {
		$when = DashboardService::label((string)$ev['start'], (bool)($ev['allDay'] ?? false), $tz, $now, $l);
		$year = (new \DateTimeImmutable((string)$ev['start']))->setTimezone($tz)->format('Y');
		if ($year !== $now->setTimezone($tz)->format('Y')) {
			$when = preg_replace('/^(\D+ \d+ \S+)/u', '$1 ' . $year, $when) ?? $when;
		}
		$parts = [$when];
		if (trim((string)($ev['location'] ?? '')) !== '') {
			$parts[] = trim((string)$ev['location']);
		}
		if ($withAccount) {
			$parts[] = (string)$ev['email'];
		}
		return implode(' · ', $parts);
	}

	/** "Aujourd'hui 10:30", "Hier", "12 nov.", "12 nov. 2025" */
	public static function day(string $iso, \DateTimeZone $tz, ?\DateTimeImmutable $now = null, ?IL10N $l = null): string {
		$d = (new \DateTimeImmutable($iso))->setTimezone($tz);
		$now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz);
		$days = (int)$d->setTime(0, 0)->diff($now->setTime(0, 0))->format('%r%a');
		return match (true) {
			$days === 0 => L10n::t($l, 'Aujourd\'hui') . ' ' . $d->format('H:i'),
			$days === 1 => L10n::t($l, 'Hier'),
			default => $d->format('j') . ' ' . DashboardService::monthName((int)$d->format('n'), $l) . ($d->format('Y') !== $now->format('Y') ? ' ' . $d->format('Y') : ''),
		};
	}
}
