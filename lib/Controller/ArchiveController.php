<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\ArchiveService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** « Archiver », and the archiving of the old read messages. */
class ArchiveController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private ArchiveService $archives,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	/** @param string[] $itemIds */
	#[NoAdminRequired]
	public function archive(int $id, array $itemIds = []): Response {
		return $this->handle(fn () => $this->archives->archive($this->accounts->find((string)$this->userId, $id), array_values(array_filter($itemIds, 'is_string'))));
	}

	#[NoAdminRequired]
	public function settings(int $id): Response {
		return $this->handle(fn () => ['months' => $this->archives->months($this->accounts->find((string)$this->userId, $id))]);
	}

	#[NoAdminRequired]
	public function save(int $id, int $months = 0): Response {
		return $this->handle(fn () => ['months' => $this->archives->setMonths($this->accounts->find((string)$this->userId, $id), $months)]);
	}
}
