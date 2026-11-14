<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\NoteService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * A message kept as a note of Nextcloud Notes.
 */
class NoteController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private NoteService $notes,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	#[NoAdminRequired]
	public function fromMessage(int $id, string $itemId): Response {
		return $this->handle(fn () => $this->notes->fromMessage((string)$this->userId, $id, $itemId));
	}
}
