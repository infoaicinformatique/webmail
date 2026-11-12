<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Exception\MailException;
use OCP\IConfig;
use OCP\IUserManager;
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
	}

	/** @return int notifications sent */
	public function checkAll(): int {
		$sent = 0;
		foreach ($this->mapper->findAll() as $account) {
			$user = $this->users->get($account->getUserId());
			if ($user === null || !$user->isEnabled() || !$this->isEnabled($account->getUserId(), $account->getId())) {
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
		$client = $this->accounts->getClient($account);

		if ($since === '') {
			// first check: what is there already is not new
			$r = $client->newMessages(null, 5);
			[, $nextSeen] = self::fresh($r['items'], [], $r['latest']);
			$this->remember($userId, $id, $r['latest'], $nextSeen);
			return 0;
		}

		$r = $client->newMessages($since, 20);
		[$fresh, $nextSeen] = self::fresh($r['items'], is_array($seen) ? $seen : [], $r['latest']);
		foreach (array_slice($fresh, 0, self::MAX) as $item) {
			$this->notify($account, $item);
		}
		$this->remember($userId, $id, $r['latest'], $nextSeen);
		return min(count($fresh), self::MAX);
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

	/** @param array<string, mixed> $item */
	private function notify(Account $account, array $item): void {
		$from = $item['from'] ?? null;
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
			])
			->setMessage('preview', ['preview' => mb_substr((string)($item['preview'] ?? ''), 0, 200)]);
		$this->notifications->notify($n);
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
	 * What the notification says. Nextcloud shows the message on one line:
	 * the parts are separated by dashes, not line breaks.
	 * @param array<string, mixed> $p subject parameters
	 * @return array{0: string, 1: string} subject, message
	 */
	public static function texts(array $p, string $preview): array {
		$subject = trim((string)($p['subject'] ?? ''));
		$parts = ['« ' . ($subject !== '' ? $subject : '(sans objet)') . ' »'];
		$preview = trim(preg_replace('/\s+/u', ' ', $preview) ?? $preview);
		if ($preview !== '') {
			$parts[] = mb_strlen($preview) > 140 ? mb_substr($preview, 0, 139) . '…' : $preview;
		}
		$message = implode(' — ', $parts) . ' · ' . (string)($p['email'] ?? '');
		$from = self::sender($p);
		return [$from !== '' ? 'Nouveau message ' . self::fromWord($from) . $from : 'Nouveau message', $message];
	}
}
