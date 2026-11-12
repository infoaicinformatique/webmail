<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\ShareLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Share links of Nextcloud files, to send in a message.
 */
class ShareController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private ShareLinkService $links,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 300)]
	public function link(string $path): Response {
		return $this->handle(fn () => $this->links->link((string)$this->userId, $path));
	}
}
