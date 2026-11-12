<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsContacts;
use OCA\Webmail\Service\AccountService;
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

	#[NoAdminRequired]
	public function index(int $id, int $offset = 0, int $limit = 100, string $q = ''): Response {
		$limit = max(1, min(200, $limit));
		return $this->handle(fn () => $this->contacts($id)->listContacts(max(0, $offset), $limit, $q !== '' ? $q : null));
	}

	#[NoAdminRequired]
	public function show(int $id, string $contactId): Response {
		return $this->handle(fn () => $this->contacts($id)->getContact($contactId));
	}

	/**
	 * @param array<string, mixed> $contact
	 */
	#[NoAdminRequired]
	public function create(int $id, array $contact): Response {
		return $this->handle(fn () => ['id' => $this->contacts($id)->createContact($contact)]);
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
