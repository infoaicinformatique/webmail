<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\TrustedImagesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The senders whose remote images are shown at once.
 */
class TrustedImagesController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private TrustedImagesService $trusted,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	#[NoAdminRequired]
	public function index(): Response {
		return $this->handle(fn () => ['trusted' => $this->trusted->list((string)$this->userId)]);
	}

	#[NoAdminRequired]
	public function add(string $entry): Response {
		return $this->handle(fn () => ['trusted' => $this->trusted->add((string)$this->userId, $entry)]);
	}

	#[NoAdminRequired]
	public function remove(string $entry): Response {
		return $this->handle(fn () => ['trusted' => $this->trusted->remove((string)$this->userId, $entry)]);
	}
}
