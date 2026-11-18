<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Exception\MailException;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * « Envois récurrents » of an account: the list (one preference), the text of each (one
 * preference each: it may be long), and the sending when their time comes (RecurringJob).
 */
class RecurringService {
	public function __construct(
		private AccountMapper $mapper,
		private AccountService $accounts,
		private IConfig $config,
		private ISecureRandom $random,
		private LoggerInterface $logger,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function list(Account $account, bool $withBody = false): array {
		$list = Recurring::decode($this->config->getUserValue($account->getUserId(), Application::APP_ID, Recurring::PREF . $account->getId(), '[]'));
		if ($withBody) {
			foreach ($list as &$e) {
				$e['bodyHtml'] = $this->config->getUserValue($account->getUserId(), Application::APP_ID, Recurring::BODY . $e['id'], '');
			}
		}
		return $list;
	}

	private function write(Account $account, array $list): void {
		if ($list === []) {
			$this->config->deleteUserValue($account->getUserId(), Application::APP_ID, Recurring::PREF . $account->getId());
		} else {
			$this->config->setUserValue($account->getUserId(), Application::APP_ID, Recurring::PREF . $account->getId(), (string)json_encode(array_values($list)));
		}
	}

	/**
	 * A new one, or one changed ($rid): its next sending computed again.
	 * @param string[] $to @param string[] $cc @param string[] $bcc
	 * @return array<string, mixed>
	 */
	public function save(Account $account, string $rid, array $to, array $cc, array $bcc, string $subject, string $html, array $schedule, ?\DateTimeImmutable $now = null): array {
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		if ($to === [] && $cc === [] && $bcc === []) {
			throw new MailException('Indiquez au moins un destinataire');
		}
		if (strlen($html) > Recurring::BODY_MAX) {
			throw new MailException('Message trop long pour un envoi récurrent');
		}
		try {
			$s = Recurring::schedule($schedule);
		} catch (\InvalidArgumentException) {
			throw new MailException('Fréquence ou heure invalide');
		}
		$list = $this->list($account);
		$index = null;
		foreach ($list as $i => $e) {
			if ($e['id'] === $rid) {
				$index = $i;
			}
		}
		if ($rid !== '' && $index === null) {
			throw new MailException('Envoi récurrent introuvable');
		}
		if ($index === null && count($list) >= Recurring::MAX) {
			throw new MailException('Trop d\'envois récurrents (%s au plus)', [Recurring::MAX]);
		}
		$entry = array_merge($index !== null ? $list[$index] : ['id' => $this->random->generate(12, ISecureRandom::CHAR_ALPHANUMERIC), 'created' => $now->format('c'), 'sent' => 0],
			$s, ['to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'subject' => mb_substr($subject, 0, 250), 'next' => Recurring::next($s, $now), 'paused' => false, 'errors' => 0, 'error' => '']);
		if ($index !== null) {
			$list[$index] = $entry;
		} else {
			$list[] = $entry;
		}
		$this->config->setUserValue($account->getUserId(), Application::APP_ID, Recurring::BODY . $entry['id'], $html);
		$this->write($account, $list);
		return $entry;
	}

	/** @return list<array<string, mixed>> */
	public function pause(Account $account, string $rid, bool $paused, ?\DateTimeImmutable $now = null): array {
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$list = $this->list($account);
		$found = false;
		foreach ($list as &$e) {
			if ($e['id'] === $rid) {
				$found = true;
				$e['paused'] = $paused;
				// started again: from now on, not the sendings missed meanwhile
				if (!$paused) {
					$e['next'] = Recurring::next($e, $now);
					$e['errors'] = 0;
					$e['error'] = '';
				}
			}
		}
		unset($e);
		if (!$found) {
			throw new MailException('Envoi récurrent introuvable');
		}
		$this->write($account, $list);
		return $list;
	}

	/** @return list<array<string, mixed>> */
	public function delete(Account $account, string $rid): array {
		$list = array_values(array_filter($this->list($account), fn ($e) => $e['id'] !== $rid));
		$this->config->deleteUserValue($account->getUserId(), Application::APP_ID, Recurring::BODY . $rid);
		$this->write($account, $list);
		return $list;
	}

	/** One sending of it now (« Envoyer maintenant »), the schedule unchanged. */
	public function sendNow(Account $account, string $rid): void {
		foreach ($this->list($account, true) as $e) {
			if ($e['id'] === $rid) {
				$this->send($account, $e);
				return;
			}
		}
		throw new MailException('Envoi récurrent introuvable');
	}

	private function send(Account $account, array $e, ?int $timeout = null): void {
		$this->accounts->getClient($account, $timeout)->sendMessage('new', (string)$e['subject'], (string)$e['bodyHtml'],
			(array)$e['to'], (array)$e['cc'], (array)$e['bcc']);
	}

	/** Every few minutes: the sendings whose time has come. @return int sent */
	public function sendDue(?\DateTimeImmutable $now = null): int {
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$sent = 0;
		foreach ($this->mapper->findAll() as $account) {
			$list = $this->list($account);
			if (!array_filter($list, fn ($e) => Recurring::due($e, $now))) {
				continue;
			}
			foreach ($list as &$e) {
				if (!Recurring::due($e, $now)) {
					continue;
				}
				$e['bodyHtml'] = $this->config->getUserValue($account->getUserId(), Application::APP_ID, Recurring::BODY . $e['id'], '');
				try {
					$this->send($account, $e, AccountService::BACKGROUND_TIMEOUT);
					$e['sent'] = (int)($e['sent'] ?? 0) + 1;
					$e['last'] = $now->format('c');
					$e['errors'] = 0;
					$e['error'] = '';
					// once, even after a long stop: the next one from now
					$e['next'] = Recurring::next($e, $now);
					$sent++;
				} catch (\Throwable $ex) {
					$this->logger->info('Webmail: recurring message of ' . $account->getEmail() . ' not sent: ' . $ex->getMessage(), ['app' => Application::APP_ID]);
					$e['errors'] = (int)($e['errors'] ?? 0) + 1;
					$e['error'] = mb_substr($ex->getMessage(), 0, 300);
					// tried again in an hour; paused after a few failures in a row
					$e['next'] = $now->modify('+1 hour')->format('Y-m-d\TH:i:s\Z');
					if ($e['errors'] >= Recurring::MAX_ERRORS) {
						$e['paused'] = true;
					}
				}
				unset($e['bodyHtml']);
			}
			unset($e);
			$this->write($account, $list);
		}
		return $sent;
	}
}
