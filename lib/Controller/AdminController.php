<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\ServerConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Admin only (no NoAdminRequired attribute).
 */
class AdminController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private ServerConfigService $servers,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	public function get(): Response {
		return $this->handle(fn () => ['servers' => $this->servers->getServers()]);
	}

	/**
	 * @param array<int, array<string, string>> $servers
	 */
	public function save(array $servers = []): Response {
		return $this->handle(fn () => ['servers' => $this->servers->setServers($servers)]);
	}
}
