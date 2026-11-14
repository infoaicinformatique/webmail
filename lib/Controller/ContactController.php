<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsContacts;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\ContactDuplicates;
use OCA\Webmail\Service\NextcloudGroups;
use OCA\Webmail\Service\NextcloudPeople;
use OCA\Webmail\Service\PresenceService;
use OCA\Webmail\Service\SharedFolderService;
use OCA\Webmail\Service\VCard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ContactController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private SharedFolderService $shared,
		private NextcloudPeople $people,
		private NextcloudGroups $groups,
		private PresenceService $presence,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	/**
	 * @param string $book the address book concerned: the public folders need their own routing
	 */
	private function contacts(int $id, string $book = ''): EwsContacts {
		$account = $this->accounts->find((string)$this->userId, $id);
		return new EwsContacts(EwsContacts::isPublic($book)
			? $this->accounts->getPublicFolderClient($account)
			: $this->accounts->getClient($account));
	}

	/**
	 * Address books: own contact folders, colleagues' shared contacts, and
	 * the company directory (search only).
	 */
	#[NoAdminRequired]
	public function books(int $id): Response {
		return $this->handle(function () use ($id) {
			$own = $this->contacts($id)->listOwnBooks();
			$shared = array_map(fn ($c) => self::sharedBook($c), $this->shared->list(SharedFolderService::CONTACTS, (string)$this->userId, $id));
			$public = array_map(fn ($c) => [
				'key' => 'public:' . $c['folder'],
				'name' => $c['name'],
				'path' => $c['folderName'],
				'folder' => $c['folder'],
				'kind' => 'public',
				'canEdit' => $c['canEdit'],
			], $this->shared->list(SharedFolderService::PUBLIC_CONTACTS, (string)$this->userId, $id));
			$directory = [['key' => 'directory', 'name' => $this->l10n()->t('Annuaire de l\'entreprise'), 'kind' => 'directory', 'canEdit' => false]];
			return ['books' => array_merge($own, $shared, $public, $directory)];
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

	/**
	 * @param array{email: string, name: string, canEdit: bool, folder: string, folderName: string} $c
	 * @return array<string, mixed>
	 */
	private static function sharedBook(array $c): array {
		return [
			'key' => EwsContacts::sharedKey($c['email'], $c['folder']),
			'name' => $c['name'],
			'email' => $c['email'],
			'folder' => $c['folder'],
			'folderName' => $c['folderName'],
			'kind' => 'shared',
			'canEdit' => $c['canEdit'],
		];
	}

	/**
	 * Add a colleague's address books. Without $folders, the books they share
	 * are looked up: a single one is added at once, otherwise they are returned
	 * as choices. With $folders ("" = their default « Contacts »), those are added.
	 * @param ?string[] $folders
	 */
	#[NoAdminRequired]
	public function addShared(int $id, string $email, ?array $folders = null): Response {
		return $this->handle(function () use ($id, $email, $folders) {
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
			$available = $contacts->listSharedBooks($email);
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
			$books = array_map(fn ($b) => self::sharedBook([
				'email' => $email,
				'name' => $name,
				'canEdit' => $b['canEdit'],
				'folder' => $b['folder'],
				'folderName' => $b['folder'] !== '' ? $b['name'] : '',
			]) + ['added' => $this->shared->find(SharedFolderService::CONTACTS, (string)$this->userId, $id, $email, $b['folder']) !== null], $available);
			if ($folders === null && count($books) > 1) {
				return ['name' => $name, 'email' => $email, 'choices' => $books];
			}
			$chosen = $folders === null ? $books : array_values(array_filter($books, fn ($b) => in_array($b['folder'], $folders, true)));
			if ($chosen === [] || ($folders !== null && count($chosen) !== count(array_unique($folders)))) {
				throw new MailException('Choisissez au moins un carnet partagé par %s', [$name]);
			}
			foreach ($chosen as $b) {
				$this->shared->save(SharedFolderService::CONTACTS, (string)$this->userId, $id, [
					'email' => $email, 'name' => $name, 'mode' => 'full', 'canEdit' => $b['canEdit'],
					'folder' => $b['folder'], 'folderName' => $b['folderName'],
				]);
			}
			return ['name' => $name, 'email' => $email, 'added' => array_map(fn ($b) => array_diff_key($b, ['added' => 1]), $chosen)];
		});
	}

	#[NoAdminRequired]
	public function removeShared(int $id, string $email, string $folder = ''): Response {
		return $this->handle(function () use ($id, $email, $folder) {
			$this->accounts->find((string)$this->userId, $id); // ownership check
			$this->shared->remove(SharedFolderService::CONTACTS, (string)$this->userId, $id, $email, $folder);
			return ['status' => 'ok'];
		});
	}

	/**
	 * Browse the public folders, one level at a time.
	 * @param string $parent folder id, "" for the top of the public folders
	 */
	#[NoAdminRequired]
	public function publicFolders(int $id, string $parent = ''): Response {
		return $this->handle(function () use ($id, $parent) {
			$added = array_column($this->shared->list(SharedFolderService::PUBLIC_CONTACTS, (string)$this->userId, $id), 'folder');
			$folders = $this->contacts($id, 'public:')->listPublicFolders($parent !== '' ? $parent : null);
			foreach ($folders as &$f) {
				$f['added'] = in_array($f['id'], $added, true);
			}
			return ['folders' => $folders];
		});
	}

	/**
	 * Add an address book of the public folders.
	 * @param string $path where it is, for display ("Société › Commercial")
	 */
	#[NoAdminRequired]
	public function addPublic(int $id, string $folder, string $path = ''): Response {
		return $this->handle(function () use ($id, $folder, $path) {
			$book = $this->contacts($id, 'public:')->getPublicBook($folder);
			$this->shared->save(SharedFolderService::PUBLIC_CONTACTS, (string)$this->userId, $id, [
				'email' => '', 'name' => $book['name'], 'mode' => 'full', 'canEdit' => $book['canEdit'],
				'folder' => $folder, 'folderName' => mb_substr(trim($path), 0, 300),
			]);
			return ['key' => 'public:' . $folder, 'name' => $book['name'], 'canEdit' => $book['canEdit']];
		});
	}

	#[NoAdminRequired]
	public function removePublic(int $id, string $folder): Response {
		return $this->handle(function () use ($id, $folder) {
			$this->accounts->find((string)$this->userId, $id); // ownership check
			$this->shared->remove(SharedFolderService::PUBLIC_CONTACTS, (string)$this->userId, $id, '', $folder);
			return ['status' => 'ok'];
		});
	}

	/**
	 * @param string $book "contacts", a folder id, "shared:<email>[:<folder>]", "public:<folder>" or "directory" (search only)
	 */
	#[NoAdminRequired]
	public function index(int $id, int $offset = 0, int $limit = 100, string $q = '', string $book = 'contacts'): Response {
		$limit = max(1, min(200, $limit));
		return $this->handle(function () use ($id, $offset, $limit, $q, $book) {
			if ($book === 'directory') {
				$items = $this->contacts($id)->searchDirectory($q);
				return ['items' => $items, 'total' => count($items), 'hasMore' => false];
			}
			if ($this->sharedEntry($id, $book) === false) {
				throw new MailException('Carnet partagé inconnu');
			}
			return $this->contacts($id, $book)->listContacts(max(0, $offset), $limit, $q !== '' ? $q : null, $book);
		});
	}

	/**
	 * The saved entry of a colleague's book or of a public one: null when
	 * $book is neither, false when it is one that was not added.
	 * @return array{canEdit: bool}|false|null
	 */
	private function sharedEntry(int $id, string $book): array|false|null {
		if (EwsContacts::isPublic($book)) {
			return $this->shared->find(SharedFolderService::PUBLIC_CONTACTS, (string)$this->userId, $id, '', substr($book, 7)) ?? false;
		}
		$parsed = EwsContacts::parseSharedKey($book);
		if ($parsed === null) {
			return null;
		}
		return $this->shared->find(SharedFolderService::CONTACTS, (string)$this->userId, $id, $parsed[0], $parsed[1]) ?? false;
	}

	/** « Doublons » are looked for in one's own books only (not a colleague's, nor the public ones). */
	private function assertOwnBook(int $id, string $book): void {
		if ($book === 'directory' || $this->sharedEntry($id, $book) !== null) {
			throw new MailException('Les doublons se cherchent dans vos propres carnets');
		}
	}

	/** The groups of contacts that look like the same person. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 300)]
	public function duplicates(int $id, string $book = 'contacts'): Response {
		return $this->handle(function () use ($id, $book) {
			$this->assertOwnBook($id, $book);
			$groups = ContactDuplicates::groups($this->contacts($id, $book)->exportContacts($book));
			$slim = fn (array $c) => [
				'id' => $c['id'], 'displayName' => (string)($c['displayName'] ?? ''), 'emails' => array_values((array)($c['emails'] ?? [])),
				'company' => (string)($c['company'] ?? ''), 'phone' => (string)($c['mobilePhone'] ?? '') ?: (string)($c['businessPhone'] ?? ''),
			];
			return ['groups' => array_map(fn ($g) => array_map($slim, $g), $groups)];
		});
	}

	/**
	 * Merge contacts into one: the card $keepId completed by the others, which are deleted.
	 * @param string[] $ids the other contacts
	 */
	#[NoAdminRequired]
	public function mergeDuplicates(int $id, string $keepId, array $ids, string $book = 'contacts'): Response {
		return $this->handle(function () use ($id, $keepId, $ids, $book) {
			$this->assertOwnBook($id, $book);
			$ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn ($x) => $x !== '' && $x !== $keepId)));
			if ($ids === [] || count($ids) > 20) {
				throw new MailException('Choisissez les contacts à fusionner');
			}
			$contacts = $this->contacts($id, $book);
			$keep = $contacts->getContact($keepId);
			$others = array_map(fn ($x) => $contacts->getContact($x), $ids);
			foreach (array_merge([$keep], $others) as $c) {
				if (!empty($c['isGroup'])) {
					throw new MailException('Un groupe ne se fusionne pas avec un contact');
				}
			}
			$contacts->updateContact($keepId, ContactDuplicates::merge($keep, $others));
			foreach ($ids as $x) {
				$contacts->deleteContact($x);
			}
			return ['id' => $keepId, 'merged' => count($ids)];
		});
	}

	private function assertKnownBook(int $id, string $book): void {
		if ($this->sharedEntry($id, $book) === false) {
			throw new MailException('Carnet inconnu');
		}
	}

	/**
	 * @param string $book the book the contact is in (needed for the public folders)
	 */
	#[NoAdminRequired]
	public function show(int $id, string $contactId, string $book = ''): Response {
		return $this->handle(function () use ($id, $contactId, $book) {
			$this->assertKnownBook($id, $book);
			return $this->contacts($id, $book)->getContact($contactId);
		});
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
			$entry = $this->sharedEntry($id, $book);
			if ($entry !== null) {
				if ($entry === false || !$entry['canEdit']) {
					throw new MailException('Vous ne pouvez pas ajouter de contact dans ce carnet');
				}
			}
			$c = $this->contacts($id, $book);
			return ['id' => !empty($contact['isGroup']) ? $c->createGroup($contact, $book) : $c->createContact($contact, $book)];
		});
	}

	/**
	 * @param array<string, mixed> $contact
	 */
	#[NoAdminRequired]
	public function update(int $id, string $contactId, array $contact, string $book = ''): Response {
		return $this->handle(function () use ($id, $contactId, $contact, $book) {
			$this->assertKnownBook($id, $book);
			$c = $this->contacts($id, $book);
			if (!empty($contact['isGroup'])) {
				$c->updateGroup($contactId, $contact);
			} else {
				$c->updateContact($contactId, $contact);
			}
			return ['status' => 'ok'];
		});
	}

	/**
	 * The contacts of a book as a vCard file (.vcf), to open in Outlook,
	 * Nextcloud Contacts, a phone…
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 20, period: 300)]
	public function export(int $id, string $book = 'contacts', string $name = 'Contacts'): Response {
		return $this->handle(function () use ($id, $book, $name) {
			if ($book === 'directory') {
				throw new MailException('L\'annuaire de l\'entreprise ne s\'exporte pas');
			}
			$this->assertKnownBook($id, $book);
			$vcf = VCard::export($this->contacts($id, $book)->exportContacts($book));
			$file = VCard::fileName($name);
			return new DataDownloadResponse($vcf, $file . '.vcf', 'text/vcard; charset=utf-8');
		});
	}

	/**
	 * Add the cards of a vCard file to a book. The people already there
	 * (same e-mail address) are left out.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	public function import(int $id, string $book = 'contacts'): Response {
		return $this->handle(function () use ($id, $book) {
			if ($book === 'directory') {
				throw new MailException('L\'annuaire de l\'entreprise est en lecture seule');
			}
			$entry = $this->sharedEntry($id, $book);
			if ($entry === false || ($entry !== null && !$entry['canEdit'])) {
				throw new MailException('Vous ne pouvez pas ajouter de contact dans ce carnet');
			}
			$file = $this->request->getUploadedFile('file');
			if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string)$file['tmp_name'])) {
				throw new MailException('Choisissez un fichier vCard (.vcf)');
			}
			if ((int)$file['size'] > 20 * 1024 * 1024) {
				throw new MailException('Fichier trop volumineux (20 Mo au plus)');
			}
			$cards = VCard::parse((string)file_get_contents((string)$file['tmp_name']));
			if ($cards === []) {
				throw new MailException('Aucun contact dans ce fichier (est-ce bien un fichier vCard ?)');
			}
			if (count($cards) > EwsContacts::MAX_TRANSFER) {
				throw new MailException('Trop de contacts dans ce fichier (%s au plus)', [EwsContacts::MAX_TRANSFER]);
			}
			$contacts = $this->contacts($id, $book);
			$known = $contacts->knownEmails($book);
			$created = 0;
			$skipped = 0;
			$failed = [];
			foreach ($cards as $card) {
				$emails = array_map('mb_strtolower', $card['emails']);
				if ($emails !== [] && array_intersect_key($known, array_flip($emails)) !== []) {
					$skipped++;
					continue;
				}
				try {
					$contacts->createContact($card, $book);
					$created++;
					foreach ($emails as $e) {
						$known[$e] = true;
					}
				} catch (MailException $e) {
					$failed[] = ['name' => $card['displayName'] ?: ($card['emails'][0] ?? '?'), 'error' => $this->errorMessage($e)];
				}
			}
			return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed];
		});
	}

	#[NoAdminRequired]
	public function delete(int $id, string $contactId, string $book = ''): Response {
		return $this->handle(function () use ($id, $contactId, $book) {
			$this->assertKnownBook($id, $book);
			$this->contacts($id, $book)->deleteContact($contactId, EwsContacts::isPublic($book));
			return ['status' => 'ok'];
		});
	}

	/**
	 * Address autocompletion (personal contacts + company directory).
	 */
	/**
	 * The photo of a contact (an image), nothing (204) when it has none.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function photo(int $id, string $contactId, string $book = ''): Response {
		return $this->handle(function () use ($id, $contactId, $book) {
			$this->assertKnownBook($id, $book);
			return self::image($this->contacts($id, $book)->contactPhoto($contactId), 300);
		});
	}

	/**
	 * The photo of a person of the directory (their Exchange account).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function userPhoto(int $id, string $email): Response {
		return $this->handle(fn () => self::image($this->contacts($id)->userPhoto($email), 86400));
	}

	/**
	 * A new photo for a contact (data: URL of a JPEG, PNG or GIF), or none (photo = '').
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 300)]
	public function setPhoto(int $id, string $contactId, string $photo = '', string $book = ''): Response {
		return $this->handle(function () use ($id, $contactId, $photo, $book) {
			$this->assertKnownBook($id, $book);
			$entry = $this->sharedEntry($id, $book);
			if (is_array($entry) && !$entry['canEdit']) {
				throw new MailException('Ce carnet est en lecture seule');
			}
			$content = '';
			if ($photo !== '') {
				if (!preg_match('#^data:image/(jpeg|png|gif);base64,([A-Za-z0-9+/=]+)$#', $photo, $m)) {
					throw new MailException('La photo doit être une image JPEG, PNG ou GIF');
				}
				$content = (string)base64_decode($m[2], true);
			}
			$this->contacts($id, $book)->setContactPhoto($contactId, $content);
			return ['hasPhoto' => $content !== ''];
		});
	}

	/** @param ?array{contentType: string, content: string} $img */
	private static function image(?array $img, int $seconds): Response {
		if ($img === null) {
			// no photo: nothing (not an error: the page shows the initials instead), remembered as well
			$none = new DataDisplayResponse('', Http::STATUS_NO_CONTENT, ['Content-Type' => 'image/png']);
			$none->cacheFor($seconds);
			return $none;
		}
		$response = new DataDisplayResponse($img['content'], Http::STATUS_OK, [
			'Content-Type' => $img['contentType'],
			// never read as anything but this image
			'X-Content-Type-Options' => 'nosniff',
			'Content-Disposition' => 'inline; filename="photo"',
		]);
		$response->cacheFor($seconds);
		return $response;
	}

	#[NoAdminRequired]
	public function resolve(int $id, string $q = ''): Response {
		// Exchange (contacts, directory), then the people of Nextcloud (Contacts, accounts of this Nextcloud)
		return $this->handle(fn () => ['results' => array_merge(
			NextcloudPeople::merge($this->contacts($id)->resolve($q), $this->people->search($q)),
			// the groups of Nextcloud last: one choice adds all their members
			$this->groups->search((string)$this->userId, $q),
		)]);
	}

	/** The addresses of a Nextcloud group's members, to write to all of them. */
	#[NoAdminRequired]
	public function groupMembers(string $gid): Response {
		return $this->handle(fn () => $this->groups->members((string)$this->userId, $gid));
	}

	/**
	 * Who is on line among these addresses (the Nextcloud status of their accounts).
	 * @param array<mixed> $emails
	 */
	#[NoAdminRequired]
	public function presence(array $emails = []): Response {
		return $this->handle(fn () => ['presence' => (object)$this->presence->forEmails($emails)]);
	}
}
