<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Exception\MailException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud notifications (the bell) for new messages, sent by a background
 * job, so they arrive even when no webmail tab is open (and on the phone
 * with the Nextcloud app). Opening or reading the message removes its
 * notification.
 */
class MailNotificationService {
	/** at most this many notifications per account and check; the newest */
	public const MAX = 5;

	public function __construct(
		private AccountMapper $mapper,
		private AccountService $accounts,
		private IConfig $config,
		private IManager $notifications,
		private IUserManager $users,
		private LoggerInterface $logger,
		private MatrixService $matrix,
		private IFactory $l10n,
		private QuietHoursService $quiet,
	) {
	}

	public function isEnabled(string $userId, int $accountId): bool {
		return $this->config->getUserValue($userId, Application::APP_ID, 'bell_' . $accountId, '1') === '1';
	}

	public function setEnabled(string $userId, int $accountId, bool $enabled): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'bell_' . $accountId, $enabled ? '1' : '0');
		if (!$enabled) {
			$this->forget($userId, $accountId);
		}
	}

	/** The next check starts from scratch (no flood of old messages when switched back on). */
	public function forget(string $userId, int $accountId): void {
		$this->config->deleteUserValue($userId, Application::APP_ID, 'bell_since_' . $accountId);
		$this->config->deleteUserValue($userId, Application::APP_ID, 'bell_seen_' . $accountId);
		$this->config->deleteUserValue($userId, Application::APP_ID, 'bell_sub_' . $accountId);
	}

	/** @return int notifications sent */
	public function checkAll(): int {
		$sent = 0;
		foreach ($this->mapper->findAll() as $account) {
			$user = $this->users->get($account->getUserId());
			// (the bell off: the messages of the priority senders are still told)
			if ($user === null || !$user->isEnabled()
				|| (!$this->isEnabled($account->getUserId(), $account->getId()) && $this->vips($account) === [])) {
				continue;
			}
			try {
				$sent += $this->check($account);
			} catch (MailException $e) {
				// wrong password, server down…: next time
				$this->logger->info('Webmail: new messages of ' . $account->getEmail() . ' not checked: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			} catch (\Throwable $e) {
				$this->logger->warning('Webmail: new messages of ' . $account->getEmail() . ' not checked', ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
		return $sent;
	}

	public function check(Account $account): int {
		$userId = $account->getUserId();
		$id = $account->getId();
		$since = $this->config->getUserValue($userId, Application::APP_ID, 'bell_since_' . $id, '');
		$seen = json_decode($this->config->getUserValue($userId, Application::APP_ID, 'bell_seen_' . $id, '[]'), true);
		$seen = is_array($seen) ? $seen : [];
		$sub = json_decode($this->config->getUserValue($userId, Application::APP_ID, 'bell_sub_' . $id, ''), true);
		$client = $this->accounts->getClient($account, AccountService::BACKGROUND_TIMEOUT);

		if ($since === '') {
			// first check: subscribe (nothing arriving from now on is missed), and what is there already is not new
			try {
				$this->subscribe($client, $userId, $id);
			} catch (MailException $e) {
				$this->logger->info('Webmail: no subscription for ' . $account->getEmail() . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
			$r = $client->newMessages(null, 5);
			[, $nextSeen] = self::fresh($r['items'], [], $r['latest']);
			$this->remember($userId, $id, $r['latest'], $nextSeen);
			return 0;
		}

		// the usual way: Exchange tells which messages arrived since the last check (one small request)
		if (is_array($sub) && !empty($sub['id']) && !empty($sub['watermark'])) {
			$events = $client->newMailEvents((string)$sub['id'], (string)$sub['watermark']);
			if ($events !== null) {
				$this->config->setUserValue($userId, Application::APP_ID, 'bell_sub_' . $id,
					json_encode(['id' => $sub['id'], 'watermark' => $events['watermark']]));
				if ($events['itemIds'] === []) {
					return 0;
				}
				$items = self::unreadNewestFirst($client->summaries($events['itemIds']));
				$fresh = array_values(array_filter($items, fn ($i) => !in_array($i['id'], $seen, true)));
				foreach (array_slice($fresh, 0, self::MAX) as $item) {
					$this->notify($account, $item);
				}
				$dates = array_filter(array_map(fn ($i) => (string)$i['date'], $items));
				$this->remember($userId, $id, $dates !== [] ? max(max($dates), $since) : $since,
					self::seenIds(array_column($items, 'id'), $seen));
				return min(count($fresh), self::MAX);
			}
		}

		// no subscription, or Exchange dropped it (no check for a long while): subscribe again,
		// then catch up with the dates (a message reported both ways is announced once)
		try {
			$this->subscribe($client, $userId, $id);
		} catch (MailException $e) {
			// the dates alone, as before
			$this->logger->info('Webmail: no subscription for ' . $account->getEmail() . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
		$r = $client->newMessages($since, 20);
		[$fresh, $nextSeen] = self::fresh($r['items'], $seen, $r['latest']);
		foreach (array_slice($fresh, 0, self::MAX) as $item) {
			$this->notify($account, $item);
		}
		$this->remember($userId, $id, $r['latest'], self::seenIds(array_merge($nextSeen, array_column($fresh, 'id')), $seen));
		return min(count($fresh), self::MAX);
	}

	/** Exchange forgets a subscription not checked for this long (minutes): several missed runs of the job. */
	public const SUBSCRIPTION_TIMEOUT = 60;

	private function subscribe(EwsClient $client, string $userId, int $accountId): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'bell_sub_' . $accountId,
			json_encode($client->subscribeInbox(self::SUBSCRIPTION_TIMEOUT)));
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>> the unread ones, newest first
	 */
	public static function unreadNewestFirst(array $items): array {
		$items = array_values(array_filter($items, fn ($i) => empty($i['isRead'])));
		usort($items, fn ($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
		return $items;
	}

	/**
	 * The messages already announced, newest first, 50 at most.
	 * @param string[] $new
	 * @param string[] $seen
	 * @return string[]
	 */
	public static function seenIds(array $new, array $seen): array {
		return array_slice(array_values(array_unique(array_merge(array_map('strval', $new), $seen))), 0, 50);
	}

	/**
	 * Messages not announced yet, and those to remember: the ones received in
	 * the same second as the newest (the next check asks "on or after" it).
	 * @param array<int, array<string, mixed>> $items newest first
	 * @param string[] $seen
	 * @return array{0: array<int, array<string, mixed>>, 1: string[]}
	 */
	public static function fresh(array $items, array $seen, string $latest): array {
		$fresh = array_values(array_filter($items, fn ($i) => !in_array($i['id'], $seen, true)));
		$keep = array_values(array_map(fn ($i) => (string)$i['id'], array_filter($items, fn ($i) => (string)$i['date'] === $latest)));
		return [$fresh, $keep];
	}

	/** @param string[] $seen */
	private function remember(string $userId, int $accountId, string $since, array $seen): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'bell_since_' . $accountId, $since);
		$this->config->setUserValue($userId, Application::APP_ID, 'bell_seen_' . $accountId, json_encode($seen));
	}

	/** @return list<string> the priority senders of the account */
	private function vips(Account $account): array {
		return VipList::decode($this->config->getUserValue($account->getUserId(), Application::APP_ID, VipList::PREF . $account->getId(), '[]'));
	}

	/** @param array<string, mixed> $item */
	private function notify(Account $account, array $item): void {
		$from = $item['from'] ?? null;
		$vips = $this->vips($account);
		$vip = VipList::has($vips, is_array($from) ? $from : null);
		// the bell off: only the priority senders
		if (!$vip && !$this->isEnabled($account->getUserId(), $account->getId())) {
			return;
		}
		// « Heures calmes »: nothing, or only the priority senders
		if ($this->quiet->silences($account->getUserId(), $vip ? 'vip' : 'message')) {
			return;
		}
		$n = $this->notifications->createNotification();
		$date = date_create_immutable((string)($item['date'] ?? '')) ?: new \DateTimeImmutable();
		$n->setApp(Application::APP_ID)
			->setUser($account->getUserId())
			->setDateTime(\DateTime::createFromImmutable($date))
			->setObject('message', self::objectId((string)$item['id']))
			->setSubject('new_message', [
				'account' => $account->getId(),
				'email' => $account->getEmail(),
				'from' => is_array($from) ? (string)(($from['name'] ?? '') !== '' ? $from['name'] : ($from['email'] ?? '')) : '',
				'subject' => mb_substr((string)($item['subject'] ?? ''), 0, 200),
				'itemId' => (string)$item['id'],
				'vip' => $vip,
			])
			->setMessage('preview', ['preview' => mb_substr((string)($item['preview'] ?? ''), 0, 200)]);
		$this->notifications->notify($n);
		$this->alertOnMatrix($account, $item, $n->getSubjectParameters());
	}

	/**
	 * The same news in the user's Matrix room (Element), when asked for: every new message,
	 * or the important ones only. A Matrix server down does not stop the bell.
	 *
	 * @param array<string, mixed> $item
	 * @param array<string, mixed> $p the parameters of the notification
	 */
	private function alertOnMatrix(Account $account, array $item, array $p): void {
		$userId = $account->getUserId();
		$mode = $this->matrix->settings($userId)['alerts'];
		if (!self::matrixWanted($mode, $item)) {
			return;
		}
		try {
			$user = $this->users->get($userId);
			$l = $this->l10n->get(Application::APP_ID, $user !== null ? $this->l10n->getUserLanguage($user) : null);
			[$title, $message] = self::texts($p, (string)($item['preview'] ?? ''), $l);
			$this->matrix->send($userId, '', '📬 ' . $title . "\n" . $message);
		} catch (\Throwable $e) {
			$this->logger->info('Webmail: new message of ' . $account->getEmail() . ' not posted on Matrix: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}

	/** « all »: every new message; « important »: those of high importance; else none. */
	public static function matrixWanted(string $mode, array $item): bool {
		return $mode === 'all' || ($mode === 'important' && ($item['importance'] ?? 'Normal') === 'High');
	}

	/**
	 * Remove the notifications of these messages (opened, read, moved, deleted).
	 * @param string[] $itemIds
	 */
	public function dismiss(string $userId, array $itemIds): void {
		foreach ($itemIds as $itemId) {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)->setUser($userId)->setObject('message', self::objectId((string)$itemId));
			$this->notifications->markProcessed($n);
		}
	}

	/** Exchange ids are longer than the 64 characters a notification object id can hold. */
	public static function objectId(string $itemId): string {
		return hash('sha256', $itemId);
	}

	/** @param array<string, mixed> $p subject parameters of the notification */
	public static function sender(array $p): string {
		$from = trim((string)($p['from'] ?? ''));
		return $from;
	}

	/** "de Paul", "d'Anne" */
	public static function fromWord(string $name): string {
		return preg_match('/^[aeiouyàâäéèêëîïôöùûü]/iu', $name) ? "d'" : 'de ';
	}

	/**
	 * "Nouveau message de Paul", "Nouveau message d'Anne", "Nouveau message".
	 * @param ?string $shown what stands for the sender in the text (the « {sender} » of a rich subject), the name by default
	 */
	public static function title(string $from, ?IL10N $l = null, ?string $shown = null): string {
		if ($from === '') {
			return L10n::t($l, 'Nouveau message');
		}
		return self::fromWord($from) === "d'"
			? L10n::t($l, 'Nouveau message d\'%s', [$shown ?? $from])
			: L10n::t($l, 'Nouveau message de %s', [$shown ?? $from]);
	}

	/**
	 * What the notification says. Nextcloud shows the message on one line:
	 * the parts are separated by dashes, not line breaks.
	 * @param array<string, mixed> $p subject parameters
	 * @return array{0: string, 1: string} subject, message
	 */
	public static function texts(array $p, string $preview, ?IL10N $l = null): array {
		$subject = trim((string)($p['subject'] ?? ''));
		$parts = [L10n::t($l, '« %s »', [$subject !== '' ? $subject : L10n::t($l, '(sans objet)')])];
		$preview = trim(preg_replace('/\s+/u', ' ', $preview) ?? $preview);
		if ($preview !== '') {
			$parts[] = mb_strlen($preview) > 140 ? mb_substr($preview, 0, 139) . '…' : $preview;
		}
		$message = implode(' — ', $parts) . ' · ' . (string)($p['email'] ?? '');
		return [(!empty($p['vip']) ? '⭐ ' : '') . self::title(self::sender($p), $l), $message];
	}
}
