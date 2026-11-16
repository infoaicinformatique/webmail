<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\SenderListService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** « Expéditeurs bloqués et autorisés » of an account. */
class SenderListController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private SenderListService $senders,
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
		return $this->handle(fn () => $this->senders->lists($this->accounts->find((string)$this->userId, $id)));
	}

	/**
	 * @param string[] $add
	 * @param string[] $remove
	 */
	#[NoAdminRequired]
	public function change(int $id, string $kind, array $add = [], array $remove = []): Response {
		return $this->handle(fn () => $this->senders->change($this->accounts->find((string)$this->userId, $id), $kind,
			array_values(array_filter($add, 'is_string')), array_values(array_filter($remove, 'is_string'))));
	}
}
