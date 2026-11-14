<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\ContactCopyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** The copy of the Exchange contacts in Nextcloud Contacts. */
class ContactCopyController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private ContactCopyService $copies,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	#[NoAdminRequired]
	public function status(int $id): Response {
		return $this->handle(function () use ($id) {
			$this->accounts->find((string)$this->userId, $id);
			return new JSONResponse($this->copies->status((string)$this->userId, $id));
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	public function copy(int $id): Response {
		return $this->handle(function () use ($id) {
			$result = $this->copies->copy($this->accounts->find((string)$this->userId, $id));
			return new JSONResponse($result + $this->copies->status((string)$this->userId, $id));
		});
	}

	#[NoAdminRequired]
	public function remove(int $id): Response {
		return $this->handle(function () use ($id) {
			$this->accounts->find((string)$this->userId, $id);
			$this->copies->remove((string)$this->userId, $id);
			return new JSONResponse(['enabled' => false]);
		});
	}
}
