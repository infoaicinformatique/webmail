<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Exception\MailException;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ICacheFactory;
use OCP\ICertificateManager;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class AccountService {
	public function __construct(
		private AccountMapper $mapper,
		private ServerConfigService $servers,
		private ICrypto $crypto,
		private IConfig $config,
		private ICacheFactory $cacheFactory,
		private ICertificateManager $certificates,
		private IAppManager $apps,
		private LoggerInterface $logger,
	) {
	}

	/** Timeout of the requests made by the background jobs: one server down must not hold up the others. */
	public const BACKGROUND_TIMEOUT = 20;

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

		$url = $this->servers->findUrl($email);
		$discovered = false;
		if ($url === null) {
			// a domain missing from the table: its server found by Autodiscover, if the administrator allows it
			$url = $this->discover($email, $password);
			$discovered = true;
		}
		$this->buildClient($url, $email, $password)->testConnection();
		if ($discovered) {
			$this->servers->rememberDiscovered(ServerConfigService::domainOf($email), $url);
		}

		$account = new Account();
		$account->setUserId($userId);
		$account->setEmail($email);
		$account->setDisplayName($displayName !== null && trim($displayName) !== '' ? trim($displayName) : null);
		$account->setPassword($this->crypto->encrypt($password));
		$account->setSignature(null);
		return $this->mapper->insert($account);
	}

	/**
	 * The EWS server of an address, found by Autodiscover (as Outlook does:
	 * autodiscover.<domain>, then <domain>), on a server the administrator
	 * allows, never on the local network.
	 * @throws MailException
	 */
	public function discover(string $email, string $password): string {
		$domain = ServerConfigService::domainOf($email);
		$settings = $this->servers->getAutodiscover();
		if (!$settings['enabled']) {
			// the usual message: the domain is missing from the table
			$this->servers->resolveUrl($email);
		}
		foreach (EwsClient::autodiscoverUrls($domain) as $endpoint) {
			$host = (string)parse_url($endpoint, PHP_URL_HOST);
			if (!$this->servers->isReachableHost($host)) {
				continue;
			}
			$found = $this->buildClient($endpoint, $email, $password, 20)->discoverEwsUrl($endpoint);
			if ($found === null) {
				continue;
			}
			try {
				$url = ServerConfigService::normalizeUrl($found);
			} catch (MailException) {
				continue;
			}
			$ewsHost = (string)parse_url($url, PHP_URL_HOST);
			if (!ServerConfigService::isAllowedHost($ewsHost, $settings['hosts'])) {
				$this->logger->warning('Webmail: Autodiscover of ' . $domain . ' gave ' . $ewsHost . ', not among the servers allowed by the administrator', ['app' => 'webmail']);
				throw new MailException('Le serveur Exchange trouvé pour « %1$s » (%2$s) n\'est pas parmi ceux autorisés par l\'administrateur.', [$domain, $ewsHost]);
			}
			if (!$this->servers->isReachableHost($ewsHost)) {
				continue;
			}
			return $url;
		}
		throw new MailException('Aucun serveur Exchange n\'a été trouvé automatiquement pour le domaine « %s ». Vérifiez l\'adresse et le mot de passe, ou demandez à votre administrateur d\'ajouter ce domaine.', [$domain]);
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
			$signature = HtmlSanitizer::signature($signature);
			$account->setSignature($signature !== '' ? $signature : null);
		}
		return $this->mapper->update($account);
	}

	public function delete(string $userId, int $id): void {
		$this->mapper->delete($this->find($userId, $id));
		// everything remembered for this account: shared and public calendars
		// and address books, shared mailboxes, notification settings and state
		foreach (self::accountKeys($this->config->getUserKeys($userId, 'webmail'), $id) as $key) {
			$this->config->deleteUserValue($userId, 'webmail', $key);
		}
	}

	/**
	 * The preferences of an account: their keys end with « _<id> »
	 * (shared_calendars_3, public_contacts_3, mailboxes_3, bell_seen_3…).
	 * @param string[] $keys
	 * @return string[]
	 */
	public static function accountKeys(array $keys, int $id): array {
		return array_values(array_filter($keys, fn ($k) => preg_match('/^[a-z_]+_' . $id . '$/', $k) === 1));
	}

	/**
	 * @throws MailException
	 */
	public function getClient(Account $account, ?int $timeout = null): EwsClient {
		try {
			$password = $this->crypto->decrypt($account->getPassword());
		} catch (\Exception) {
			throw new MailException('Mot de passe enregistré illisible, merci de le saisir à nouveau dans les paramètres du compte');
		}
		return $this->buildClient($this->servers->resolveUrl($account->getEmail()), $account->getEmail(), $password, $timeout);
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

	/**
	 * Mailbox size limit (bytes, null if unknown), remembered for an hour.
	 */
	public function mailboxQuota(Account $account, EwsClient $client): ?int {
		$cache = $this->cacheFactory->createDistributed('webmail-quota');
		$key = 'q_' . $account->getId();
		$value = $cache->get($key);
		if (!is_int($value)) {
			try {
				$value = $client->mailboxQuota() ?? 0;
			} catch (MailException) {
				$value = 0;
			}
			$cache->set($key, $value, 3600);
		}
		return $value > 0 ? $value : null;
	}

		private function buildClient(string $url, string $email, string $password, ?int $timeout = null): EwsClient {
		$verify = $this->config->getAppValue('webmail', 'verify_tls', 'yes') !== 'no';
		$client = new EwsClient($url, $email, $password, $timeout ?? 60, $verify);
		$client->setNetwork(...$this->network());
		return $client;
	}

	/** @var array{0: ?string, 1: ?string, 2: string[], 3: ?string, 4: string}|null */
	private ?array $network = null;

	/**
	 * The proxy and certificates of Nextcloud (config.php, imported
	 * certificates), read once per request.
	 * @return array{0: ?string, 1: ?string, 2: string[], 3: ?string, 4: string}
	 */
	private function network(): array {
		if ($this->network === null) {
			$exclude = $this->config->getSystemValue('proxyexclude', []);
			try {
				$bundle = $this->certificates->getAbsoluteBundlePath();
			} catch (\Throwable $e) {
				$this->logger->warning('Webmail: certificate bundle of Nextcloud unavailable, the system one is used', ['app' => 'webmail', 'exception' => $e]);
				$bundle = null;
			}
			$this->network = [
				$this->config->getSystemValueString('proxy', '') ?: null,
				$this->config->getSystemValueString('proxyuserpwd', '') ?: null,
				is_array($exclude) ? array_map('strval', $exclude) : [],
				$bundle,
				'Nextcloud-Webmail/' . $this->apps->getAppVersion('webmail'),
			];
		}
		return $this->network;
	}
}
