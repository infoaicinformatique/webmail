<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Ews\EwsContacts;
use OCA\Webmail\Exception\MailException;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * « Copier dans Nextcloud Contacts »: the contacts of an Exchange account in
 * an address book of their own in Nextcloud (« Exchange — user@… »), for
 * the Contacts app, the phones synced with Nextcloud (CardDAV) and the other
 * apps. One way only: Exchange stays the reference, and the copy is brought
 * up to date every 6 hours (and on demand).
 */
class ContactCopyService {
	public function __construct(
		private AccountMapper $mapper,
		private AccountService $accounts,
		private IConfig $config,
		private IUserManager $users,
		private LoggerInterface $logger,
	) {
	}

	/** The Nextcloud address book of an account (its URI). */
	public static function bookUri(int $accountId): string {
		return 'webmail-exchange-' . $accountId;
	}

	private function backend(): object {
		if (!class_exists(\OCA\DAV\CardDAV\CardDavBackend::class)) {
			throw new MailException('L\'application « dav » de Nextcloud n\'est pas disponible');
		}
		return \OCP\Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
	}

	private static function principal(string $userId): string {
		return 'principals/users/' . $userId;
	}

	/** @return array{enabled: bool, last: string, count: int} */
	public function status(string $userId, int $accountId): array {
		$last = $this->config->getUserValue($userId, Application::APP_ID, 'contactcopy_' . $accountId, '');
		return [
			'enabled' => $last !== '',
			'last' => $last,
			'count' => (int)$this->config->getUserValue($userId, Application::APP_ID, 'contactcopy_count_' . $accountId, '0'),
		];
	}

	/**
	 * Copy (or bring up to date) the contacts of an account.
	 * @return array{created: int, updated: int, deleted: int, count: int}
	 */
	public function copy(Account $account): array {
		$userId = $account->getUserId();
		$id = $account->getId();
		$backend = $this->backend();
		$principal = self::principal($userId);
		$book = $backend->getAddressBooksByUri($principal, self::bookUri($id));
		if ($book === null) {
			$backend->createAddressBook($principal, self::bookUri($id), [
				'{DAV:}displayname' => 'Exchange — ' . $account->getEmail(),
				'{urn:ietf:params:xml:ns:carddav}addressbook-description' => 'Copie des contacts Exchange (webmail)',
			]);
			$book = $backend->getAddressBooksByUri($principal, self::bookUri($id));
			if ($book === null) {
				throw new MailException('Le carnet Nextcloud n\'a pas pu être créé');
			}
		}
		$bookId = (int)$book['id'];
		$existing = [];
		foreach ($backend->getCards($bookId) as $card) {
			$data = $card['carddata'];
			$existing[(string)$card['uri']] = is_resource($data) ? (string)stream_get_contents($data) : (string)$data;
		}
		$contacts = (new EwsContacts($this->accounts->getClient($account)))->exportContacts('contacts');
		$plan = ContactCopy::plan($contacts, $existing);
		foreach ($plan['create'] as $uri => $vcf) {
			$backend->createCard($bookId, $uri, $vcf);
		}
		foreach ($plan['update'] as $uri => $vcf) {
			$backend->updateCard($bookId, $uri, $vcf);
		}
		foreach ($plan['delete'] as $uri) {
			$backend->deleteCard($bookId, $uri);
		}
		$count = count($existing) + count($plan['create']) - count($plan['delete']);
		$this->config->setUserValue($userId, Application::APP_ID, 'contactcopy_' . $id, gmdate('c'));
		$this->config->setUserValue($userId, Application::APP_ID, 'contactcopy_count_' . $id, (string)$count);
		return ['created' => count($plan['create']), 'updated' => count($plan['update']), 'deleted' => count($plan['delete']), 'count' => $count];
	}

	/** Stop the copy and remove the Nextcloud address book. */
	public function remove(string $userId, int $accountId): void {
		$this->config->deleteUserValue($userId, Application::APP_ID, 'contactcopy_' . $accountId);
		$this->config->deleteUserValue($userId, Application::APP_ID, 'contactcopy_count_' . $accountId);
		if (!class_exists(\OCA\DAV\CardDAV\CardDavBackend::class)) {
			return;
		}
		$backend = $this->backend();
		$book = $backend->getAddressBooksByUri(self::principal($userId), self::bookUri($accountId));
		if ($book !== null) {
			$backend->deleteAddressBook((int)$book['id']);
		}
	}

	/** The background refresh of every copy turned on. */
	public function runAll(): void {
		foreach ($this->mapper->findAll() as $account) {
			$user = $this->users->get($account->getUserId());
			if ($user === null || !$user->isEnabled() || !$this->status($account->getUserId(), $account->getId())['enabled']) {
				continue;
			}
			try {
				$this->copy($account);
			} catch (MailException $e) {
				$this->logger->info('Webmail: contacts of ' . $account->getEmail() . ' not copied: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			} catch (\Throwable $e) {
				$this->logger->warning('Webmail: contacts of ' . $account->getEmail() . ' not copied', ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
	}
}
