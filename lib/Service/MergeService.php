<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Exception\MailException;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * « Envoi personnalisé »: one message per recipient, filled with their row. The first ones
 * go at once, the others by the background job, a few dozen every five minutes (under the
 * sending limits of Exchange); a report of each sending, and a notification at its end.
 */
class MergeService {
	/** sent at once, in the request */
	public const NOW = 10;
	/** sent by each run of the job, per sending */
	public const PER_RUN = 40;
	/** sendings kept with their report */
	private const KEEP = 5;

	public function __construct(
		private AccountService $accounts,
		private AccountMapper $mapper,
		private IConfig $config,
		private ICacheFactory $cacheFactory,
		private INotificationManager $notifications,
		private LoggerInterface $logger,
	) {
	}

	private function key(Account $account): string {
		return 'merge_' . $account->getId();
	}

	/** @return list<array<string, mixed>> the sendings of the account, the newest first */
	public function sendings(Account $account): array {
		$v = json_decode($this->config->getUserValue($account->getUserId(), Application::APP_ID, $this->key($account), '[]'), true);
		return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
	}

	/** @param list<array<string, mixed>> $list */
	private function keep(Account $account, array $list): void {
		$this->config->setUserValue($account->getUserId(), Application::APP_ID, $this->key($account), (string)json_encode(array_slice($list, 0, self::KEEP)));
	}

	/** What is shown of a sending (without its rows nor its text). */
	public static function report(array $s): array {
		return [
			'id' => $s['id'], 'subject' => $s['subject'], 'created' => $s['created'], 'total' => $s['total'],
			'sent' => $s['sent'], 'failed' => $s['failed'], 'left' => count($s['rows']), 'done' => $s['rows'] === [],
		];
	}

	/**
	 * A sending begun: the list read, the first messages sent now.
	 * @return array<string, mixed> its report, with the lines refused
	 */
	public function start(Account $account, string $subject, string $html, string $list): array {
		$parsed = MergeRules::parse($list);
		if ($parsed['rows'] === []) {
			throw new MailException('Aucune adresse e-mail valide dans la liste');
		}
		if (count($parsed['rows']) > MergeRules::MAX) {
			throw new MailException('Trop de destinataires : %s au plus par envoi', [MergeRules::MAX]);
		}
		if (trim($subject) === '' || trim(strip_tags($html)) === '') {
			throw new MailException('Indiquez l\'objet et le texte du message');
		}
		$sending = [
			'id' => bin2hex(random_bytes(6)),
			'subject' => mb_substr($subject, 0, 255),
			'html' => $html,
			'created' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
			'total' => count($parsed['rows']),
			'sent' => 0,
			'failed' => [],
			'rows' => $parsed['rows'],
		];
		$this->keep($account, array_merge([$sending], $this->sendings($account)));
		$sending = $this->run($account, $sending['id'], self::NOW);
		return self::report($sending) + ['refused' => $parsed['refused']];
	}

	/**
	 * Up to $max messages of a sending sent (one run at a time).
	 * @return array<string, mixed> the sending after the run
	 */
	public function run(Account $account, string $id, int $max): array {
		$lock = $this->cacheFactory->createDistributed('webmail-merge');
		$lockKey = $account->getId() . '_' . $id;
		$find = function () use ($account, $id): array {
			foreach ($this->sendings($account) as $s) {
				if ($s['id'] === $id) {
					return $s;
				}
			}
			throw new MailException('Envoi introuvable');
		};
		if (!$lock->add($lockKey, 1, 600)) {
			return $find();
		}
		try {
			$client = $this->accounts->getClient($account, AccountService::BACKGROUND_TIMEOUT);
			$s = $find();
			for ($n = 0; $n < $max && $s['rows'] !== []; $n++) {
				$row = array_shift($s['rows']);
				try {
					$client->sendMessage('new', MergeRules::fill((string)$s['subject'], $row, false), MergeRules::fill((string)$s['html'], $row, true), [$row['email']], [], []);
					$s['sent']++;
				} catch (\Throwable $e) {
					$s['failed'][] = ['email' => $row['email'], 'error' => mb_substr($e->getMessage(), 0, 200)];
				}
				// (each message kept as done: a run stopped halfway sends no one twice)
				$this->save($account, $s);
			}
			if ($s['rows'] === []) {
				$this->tell($account, $s);
			}
			return $s;
		} finally {
			$lock->remove($lockKey);
		}
	}

	private function save(Account $account, array $sending): void {
		$this->keep($account, array_map(fn ($s) => $s['id'] === $sending['id'] ? $sending : $s, $this->sendings($account)));
	}

	/** Every five minutes: the sendings under way go on. */
	public function runAll(): void {
		foreach ($this->mapper->findAll() as $account) {
			foreach ($this->sendings($account) as $s) {
				if ($s['rows'] === []) {
					continue;
				}
				try {
					$this->run($account, (string)$s['id'], self::PER_RUN);
				} catch (\Throwable $e) {
					$this->logger->warning('Webmail: personalised sending of ' . $account->getEmail() . ' paused: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				}
			}
		}
	}

	/** Stopped: the messages not sent yet are left. */
	public function stop(Account $account, string $id): array {
		$out = null;
		$list = array_map(function ($s) use ($id, &$out) {
			if ($s['id'] === $id) {
				$s['total'] -= count($s['rows']);
				$s['rows'] = [];
				$out = $s;
			}
			return $s;
		}, $this->sendings($account));
		if ($out === null) {
			throw new MailException('Envoi introuvable');
		}
		$this->keep($account, $list);
		return self::report($out);
	}

	private function tell(Account $account, array $s): void {
		$n = $this->notifications->createNotification();
		$n->setApp(Application::APP_ID)
			->setUser($account->getUserId())
			->setDateTime(new \DateTime())
			->setObject('merge', (string)$s['id'])
			->setSubject('merge_done', ['subject' => $s['subject'], 'sent' => $s['sent'], 'failed' => count($s['failed']), 'account' => $account->getId(), 'email' => $account->getEmail()]);
		$this->notifications->notify($n);
	}
}
