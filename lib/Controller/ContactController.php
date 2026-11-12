<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsContacts;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\SharedFolderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ContactController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private SharedFolderService $shared,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	private function contacts(int $id): EwsContacts {
		return new EwsContacts($this->accounts->getClient($this->accounts->find((string)$this->userId, $id)));
	}

	/**
	 * Address books: own contact folders, colleagues' shared contacts, and
	 * the company directory (search only).
	 */
	#[NoAdminRequired]
	public function books(int $id): Response {
		return $this->handle(function () use ($id) {
			$own = $this->contacts($id)->listOwnBooks();
			$shared = array_map(fn ($c) => [
				'key' => 'shared:' . $c['email'],
				'name' => $c['name'],
				'email' => $c['email'],
				'kind' => 'shared',
				'canEdit' => $c['canEdit'],
			], $this->shared->list(SharedFolderService::CONTACTS, (string)$this->userId, $id));
			$directory = [['key' => 'directory', 'name' => 'Annuaire de l\'entreprise', 'kind' => 'directory', 'canEdit' => false]];
			return ['books' => array_merge($own, $shared, $directory)];
		});
	}

	/**
	 * Who one of my address books is shared with.
	 */
	#[NoAdminRequired]
	public function permissions(int $id, string $book = 'contacts'): Response {
		return $this->handle(fn () => ['permissions' => $this->contacts($id)->getPermissions($book)]);
	}

	/**
	 * Share one of my address books without delegation.
	 * @param array<int, array{user: string, level: string}> $permissions
	 */
	#[NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\UserRateLimit(limit: 30, period: 300)]
	public function setPermissions(int $id, array $permissions, string $book = 'contacts'): Response {
		return $this->handle(function () use ($id, $permissions, $book) {
			$account = $this->accounts->find((string)$this->userId, $id);
			$contacts = new EwsContacts($this->accounts->getClient($account));
			$contacts->setPermissions($book, $permissions, $account->getEmail());
			return ['permissions' => $contacts->getPermissions($book)];
		});
	}

	#[NoAdminRequired]
	public function addShared(int $id, string $email): Response {
		return $this->handle(function () use ($id, $email) {
			$email = strtolower(trim($email));
			if (preg_match('/<([^>]+)>/', $email, $m)) {
				$email = trim($m[1]);
			}
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				throw new MailException('Adresse e-mail invalide');
			}
			$account = $this->accounts->find((string)$this->userId, $id);
			if ($account->getEmail() === $email) {
				throw new MailException('Ce sont vos propres contacts');
			}
			$contacts = new EwsContacts($this->accounts->getClient($account));
			$access = $contacts->probeSharedBook($email);
			$name = $email;
			try {
				foreach ($contacts->resolve($email) as $r) {
					if (strtolower($r['email']) === $email && $r['name'] !== '') {
						$name = $r['name'];
						break;
					}
				}
			} catch (MailException) {
				// the address is enough
			}
			$entry = ['email' => $email, 'name' => $name, 'mode' => 'full', 'canEdit' => $access['canEdit']];
			$this->shared->save(SharedFolderService::CONTACTS, (string)$this->userId, $id, $entry);
			return ['key' => 'shared:' . $email, 'kind' => 'shared', 'email' => $email, 'name' => $name, 'canEdit' => $access['canEdit']];
		});
	}

	#[NoAdminRequired]
	public function removeShared(int $id, string $email): Response {
		return $this->handle(function () use ($id, $email) {
			$this->accounts->find((string)$this->userId, $id); // ownership check
			$this->shared->remove(SharedFolderService::CONTACTS, (string)$this->userId, $id, $email);
			return ['status' => 'ok'];
		});
	}

	/**
	 * @param string $book "contacts", a folder id, "shared:<email>" or "directory" (search only)
	 */
	#[NoAdminRequired]
	public function index(int $id, int $offset = 0, int $limit = 100, string $q = '', string $book = 'contacts'): Response {
		$limit = max(1, min(200, $limit));
		return $this->handle(function () use ($id, $offset, $limit, $q, $book) {
			if ($book === 'directory') {
				$items = $this->contacts($id)->searchDirectory($q);
				return ['items' => $items, 'total' => count($items), 'hasMore' => false];
			}
			if (str_starts_with($book, 'shared:') && $this->shared->find(SharedFolderService::CONTACTS, (string)$this->userId, $id, substr($book, 7)) === null) {
				throw new MailException('Carnet partagé inconnu');
			}
			return $this->contacts($id)->listContacts(max(0, $offset), $limit, $q !== '' ? $q : null, $book);
		});
	}

	#[NoAdminRequired]
	public function show(int $id, string $contactId): Response {
		return $this->handle(fn () => $this->contacts($id)->getContact($contactId));
	}

	/**
	 * @param array<string, mixed> $contact
	 */
	#[NoAdminRequired]
	public function create(int $id, array $contact, string $book = 'contacts'): Response {
		return $this->handle(function () use ($id, $contact, $book) {
			if ($book === 'directory') {
				throw new MailException('L\'annuaire de l\'entreprise est en lecture seule');
			}
			if (str_starts_with($book, 'shared:')) {
				$entry = $this->shared->find(SharedFolderService::CONTACTS, (string)$this->userId, $id, substr($book, 7));
				if ($entry === null || !$entry['canEdit']) {
					throw new MailException('Vous ne pouvez pas ajouter de contact dans ce carnet');
				}
			}
			return ['id' => $this->contacts($id)->createContact($contact, $book)];
		});
	}

	/**
	 * @param array<string, mixed> $contact
	 */
	#[NoAdminRequired]
	public function update(int $id, string $contactId, array $contact): Response {
		return $this->handle(function () use ($id, $contactId, $contact) {
			$this->contacts($id)->updateContact($contactId, $contact);
			return ['status' => 'ok'];
		});
	}

	#[NoAdminRequired]
	public function delete(int $id, string $contactId): Response {
		return $this->handle(function () use ($id, $contactId) {
			$this->contacts($id)->deleteContact($contactId);
			return ['status' => 'ok'];
		});
	}

	/**
	 * Address autocompletion (personal contacts + company directory).
	 */
	#[NoAdminRequired]
	public function resolve(int $id, string $q = ''): Response {
		return $this->handle(fn () => ['results' => $this->contacts($id)->resolve($q)]);
	}
}
