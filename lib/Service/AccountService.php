<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Exception\MailException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\Security\ICrypto;

class AccountService {
	public function __construct(
		private AccountMapper $mapper,
		private ServerConfigService $servers,
		private ICrypto $crypto,
		private IConfig $config,
		private ICacheFactory $cacheFactory,
	) {
	}

	/** @return Account[] */
	public function findAll(string $userId): array {
		return $this->mapper->findAllForUser($userId);
	}

	/**
	 * @throws MailException
	 */
	public function find(string $userId, int $id): Account {
		try {
			return $this->mapper->findForUser($userId, $id);
		} catch (DoesNotExistException) {
			throw new MailException('Compte introuvable');
		}
	}

	/**
	 * @throws MailException
	 */
	public function create(string $userId, string $email, string $password, ?string $displayName): Account {
		$email = strtolower(trim($email));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new MailException('Adresse e-mail invalide');
		}
		if ($password === '') {
			throw new MailException('Mot de passe manquant');
		}
		foreach ($this->mapper->findAllForUser($userId) as $existing) {
			if ($existing->getEmail() === $email) {
				throw new MailException('Ce compte est déjà configuré');
			}
		}

		$url = $this->servers->resolveUrl($email);
		$this->buildClient($url, $email, $password)->testConnection();

		$account = new Account();
		$account->setUserId($userId);
		$account->setEmail($email);
		$account->setDisplayName($displayName !== null && trim($displayName) !== '' ? trim($displayName) : null);
		$account->setPassword($this->crypto->encrypt($password));
		$account->setSignature(null);
		return $this->mapper->insert($account);
	}

	/**
	 * @throws MailException
	 */
	public function update(string $userId, int $id, ?string $displayName, ?string $password, ?string $signature): Account {
		$account = $this->find($userId, $id);
		if ($password !== null && $password !== '') {
			$this->buildClient($this->servers->resolveUrl($account->getEmail()), $account->getEmail(), $password)->testConnection();
			$account->setPassword($this->crypto->encrypt($password));
		}
		if ($displayName !== null) {
			$account->setDisplayName(trim($displayName) !== '' ? trim($displayName) : null);
		}
		if ($signature !== null) {
			$account->setSignature($signature);
		}
		return $this->mapper->update($account);
	}

	public function delete(string $userId, int $id): void {
		$this->mapper->delete($this->find($userId, $id));
		// shared calendars and contacts added to this account
		foreach (['calendars', 'contacts'] as $type) {
			$this->config->deleteUserValue($userId, 'webmail', 'shared_' . $type . '_' . $id);
		}
	}

	/**
	 * @throws MailException
	 */
	public function getClient(Account $account): EwsClient {
		try {
			$password = $this->crypto->decrypt($account->getPassword());
		} catch (\Exception) {
			throw new MailException('Mot de passe enregistré illisible, merci de le saisir à nouveau dans les paramètres du compte');
		}
		return $this->buildClient($this->servers->resolveUrl($account->getEmail()), $account->getEmail(), $password);
	}

	/**
	 * A client routed to the account's public folders. The public folder
	 * mailbox comes from Autodiscover and is remembered for a day.
	 */
	public function getPublicFolderClient(Account $account): EwsClient {
		$client = $this->getClient($account);
		$cache = $this->cacheFactory->createDistributed('webmail-pf');
		$key = 'pf_' . $account->getId();
		$mailbox = $cache->get($key);
		if (!is_string($mailbox)) {
			$mailbox = (string)$client->discoverPublicFolderMailbox();
			$cache->set($key, $mailbox, 86400);
		}
		$client->usePublicFolderMailbox($mailbox !== '' ? $mailbox : null);
		return $client;
	}

	private function buildClient(string $url, string $email, string $password): EwsClient {
		$verify = $this->config->getAppValue('webmail', 'verify_tls', 'yes') !== 'no';
		return new EwsClient($url, $email, $password, 60, $verify);
	}
}
