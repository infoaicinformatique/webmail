<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Ews\EwsRules;
use OCA\Webmail\Exception\MailException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * « Expéditeurs bloqués et autorisés », kept by Exchange as two rules of the inbox (they act
 * even when the webmail is closed, and show in Outlook):
 *  - blocked: their messages go to « Courrier indésirable »;
 *  - allowed: first of all the rules, they stop the others (an address allowed in a domain
 *    blocked); and their messages put in « Courrier indésirable » by the filter of Exchange
 *    are brought back to the inbox (every quarter of an hour), which also makes Exchange trust them.
 * Exchange's own lists of junk senders cannot be read through EWS: these are the webmail's.
 */
class SenderListService {
	public const RULES = ['blocked' => 'Webmail : expéditeurs bloqués', 'allowed' => 'Webmail : expéditeurs autorisés'];
	/** the allowed entries, kept beside the rule: the job reads them without asking Exchange */
	private const ALLOWED_PREF = 'senders_allowed_';

	public function __construct(
		private AccountService $accounts,
		private AccountMapper $mapper,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	private function rules(Account $account, ?EwsClient $client = null): EwsRules {
		return new EwsRules($client ?? $this->accounts->getClient($account), $account->getEmail());
	}

	/** @return array{blocked: list<string>, allowed: list<string>} */
	public function lists(Account $account): array {
		$out = ['blocked' => [], 'allowed' => []];
		foreach ($this->rules($account)->list() as $r) {
			$kind = array_search($r['name'], self::RULES, true);
			if ($kind !== false) {
				$out[$kind] = SenderLists::clean((array)($r['conditions']['senderContains'] ?? []));
			}
		}
		return $out;
	}

	/**
	 * Entries added to a list (and taken out of the other one), or taken away.
	 * @param list<string> $add
	 * @param list<string> $remove
	 * @return array{blocked: list<string>, allowed: list<string>}
	 */
	public function change(Account $account, string $kind, array $add, array $remove = []): array {
		if (!in_array($kind, SenderLists::KINDS, true)) {
			throw new MailException('Liste inconnue');
		}
		$add = SenderLists::clean($add);
		foreach ($add as $i => $e) {
			if ($e === mb_strtolower($account->getEmail()) || $e === '@' . substr(strrchr(mb_strtolower($account->getEmail()), '@') ?: '@', 1)) {
				// (not one's own address nor one's own domain in the blocked list)
				if ($kind === 'blocked') {
					throw new MailException('Vous ne pouvez pas bloquer votre propre adresse ni votre propre domaine');
				}
			}
		}
		$remove = SenderLists::clean($remove);
		$client = $this->accounts->getClient($account);
		$rules = $this->rules($account, $client);
		$current = [];
		$ids = [];
		foreach ($rules->list() as $r) {
			$k = array_search($r['name'], self::RULES, true);
			if ($k !== false) {
				$current[$k] = SenderLists::clean((array)($r['conditions']['senderContains'] ?? []));
				$ids[$k] = $r['id'];
			}
		}
		$other = $kind === 'blocked' ? 'allowed' : 'blocked';
		$lists = ['blocked' => $current['blocked'] ?? [], 'allowed' => $current['allowed'] ?? []];
		$lists[$kind] = array_values(array_diff(array_unique(array_merge($lists[$kind], $add)), $remove));
		$lists[$other] = array_values(array_diff($lists[$other], $add));
		if (count($lists[$kind]) > SenderLists::MAX) {
			throw new MailException('Trop d\'expéditeurs dans la liste (%s au plus)', [SenderLists::MAX]);
		}
		$folders = $client->distinguishedFolderIds(['inbox', 'junkemail']);
		foreach (['allowed', 'blocked'] as $k) {
			$before = $current[$k] ?? null;
			if ($before === $lists[$k]) {
				continue;
			}
			if ($lists[$k] === []) {
				if (isset($ids[$k])) {
					$rules->delete($ids[$k]);
				}
				continue;
			}
			$rules->save([
				'id' => $ids[$k] ?? '',
				'name' => self::RULES[$k],
				'priority' => $k === 'allowed' ? 1 : (empty($lists['allowed']) ? 1 : 2),
				'conditions' => ['senderContains' => $lists[$k]],
				'actions' => $k === 'blocked'
					? ['moveTo' => $folders['junkemail'] ?? 'junkemail', 'stop' => true]
					: ['moveTo' => $folders['inbox'] ?? 'inbox', 'stop' => true],
			]);
		}
		// first the allowed, then the blocked: before the user's own rules
		foreach ($rules->list() as $r) {
			$k = array_search($r['name'], self::RULES, true);
			$want = $k === 'allowed' ? 1 : ($k === 'blocked' ? (empty($lists['allowed']) ? 1 : 2) : null);
			if ($want !== null && $r['priority'] !== $want) {
				$rules->setPriority($r['id'], $want);
			}
		}
		$this->keepAllowed($account, $lists['allowed']);
		if ($kind === 'allowed' && $add !== []) {
			$this->rescue($account, $add);
		}
		return $lists;
	}

	private function keepAllowed(Account $account, array $allowed): void {
		if ($allowed === []) {
			$this->config->deleteUserValue($account->getUserId(), Application::APP_ID, self::ALLOWED_PREF . $account->getId());
		} else {
			$this->config->setUserValue($account->getUserId(), Application::APP_ID, self::ALLOWED_PREF . $account->getId(), (string)json_encode($allowed));
		}
	}

	/**
	 * The messages of allowed senders found in « Courrier indésirable » brought back to the
	 * inbox, as « Pas indésirable » does (Exchange then trusts them).
	 * @return int how many
	 */
	public function rescue(Account $account, ?array $allowed = null): int {
		$allowed ??= SenderLists::clean((array)json_decode($this->config->getUserValue($account->getUserId(), Application::APP_ID, self::ALLOWED_PREF . $account->getId(), '[]'), true));
		if ($allowed === []) {
			return 0;
		}
		$client = $this->accounts->getClient($account, AccountService::BACKGROUND_TIMEOUT);
		$found = $client->findItems('junkemail', 0, 100);
		$ids = [];
		foreach ($found['items'] as $m) {
			if (SenderLists::matches((string)($m['from']['email'] ?? ''), $allowed)) {
				$ids[] = (string)$m['id'];
			}
		}
		if ($ids !== []) {
			$client->markAsJunk($ids, false);
		}
		return count($ids);
	}

	/** Every quarter of an hour: the allowed senders brought back from « Courrier indésirable ». */
	public function rescueAll(): void {
		foreach ($this->mapper->findAll() as $account) {
			if ($this->config->getUserValue($account->getUserId(), Application::APP_ID, self::ALLOWED_PREF . $account->getId(), '') === '') {
				continue;
			}
			try {
				$this->rescue($account);
			} catch (\Throwable $e) {
				$this->logger->info('Webmail: allowed senders of ' . $account->getEmail() . ' not checked: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
	}
}
