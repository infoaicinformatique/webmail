<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\ReplyLaterService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** « Répondre plus tard » of an account. */
class ReplyLaterController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private ReplyLaterService $replies,
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
		return $this->handle(fn () => ['messages' => $this->replies->list($this->accounts->find((string)$this->userId, $id))]);
	}

	#[NoAdminRequired]
	public function add(int $id, string $itemId, string $until): Response {
		return $this->handle(fn () => ['messages' => $this->replies->add($this->accounts->find((string)$this->userId, $id), $itemId, $until)]);
	}

	#[NoAdminRequired]
	public function remove(int $id, string $itemId): Response {
		return $this->handle(fn () => ['messages' => $this->replies->remove($this->accounts->find((string)$this->userId, $id), $itemId)]);
	}
}
