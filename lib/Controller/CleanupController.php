<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\CleanupRules;
use OCA\Webmail\Service\CleanupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** « Nettoyage automatique » of the folders of an account. */
class CleanupController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private CleanupService $cleanups,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	#[NoAdminRequired]
	public function index(int $id): Response {
		return $this->handle(fn () => ['rules' => (object)$this->cleanups->rules($this->accounts->find((string)$this->userId, $id)), 'choices' => CleanupRules::DAYS]);
	}

	#[NoAdminRequired]
	public function set(int $id, string $folderId, int $days = 0): Response {
		return $this->handle(fn () => ['rules' => (object)$this->cleanups->set($this->accounts->find((string)$this->userId, $id), $folderId, $days), 'choices' => CleanupRules::DAYS]);
	}
}
