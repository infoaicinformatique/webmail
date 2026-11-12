<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsDelegates;
use OCA\Webmail\Service\AccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Delegates of one of the user's Exchange mailboxes.
 */
class DelegateController extends Controller {
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

	private function delegates(int $id): EwsDelegates {
		$account = $this->accounts->find((string)$this->userId, $id);
		return new EwsDelegates($this->accounts->getClient($account), $account->getEmail());
	}

	#[NoAdminRequired]
	public function index(int $id): Response {
		return $this->handle(fn () => $this->delegates($id)->list());
	}

	/**
	 * @param array<string, string> $permissions calendar, inbox, contacts, tasks => None|Reviewer|Author|Editor
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 300)]
	public function create(int $id, string $email, array $permissions = [], bool $receiveCopies = false, bool $viewPrivate = false): Response {
		return $this->handle(function () use ($id, $email, $permissions, $receiveCopies, $viewPrivate) {
			$d = $this->delegates($id);
			$d->add(compact('email', 'permissions', 'receiveCopies', 'viewPrivate'));
			return $d->list();
		});
	}

	/**
	 * @param array<string, string> $permissions
	 */
	#[NoAdminRequired]
	public function update(int $id, string $email, array $permissions = [], bool $receiveCopies = false, bool $viewPrivate = false): Response {
		return $this->handle(function () use ($id, $email, $permissions, $receiveCopies, $viewPrivate) {
			$d = $this->delegates($id);
			$d->update(compact('email', 'permissions', 'receiveCopies', 'viewPrivate'));
			return $d->list();
		});
	}

	#[NoAdminRequired]
	public function delete(int $id, string $email): Response {
		return $this->handle(function () use ($id, $email) {
			$d = $this->delegates($id);
			$d->remove($email);
			return $d->list();
		});
	}

	#[NoAdminRequired]
	public function meetingRequests(int $id, string $mode): Response {
		return $this->handle(function () use ($id, $mode) {
			$d = $this->delegates($id);
			$d->setDeliverMeetingRequests($mode);
			return $d->list();
		});
	}
}
