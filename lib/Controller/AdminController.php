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
		return $this->handle(fn () => $this->state());
	}

	/**
	 * @param array<int, array<string, string>> $servers
	 * @param ?array{enabled?: bool, hosts?: string[]} $autodiscover
	 * @param ?string $visio the address of the video meetings (Element Call…), '' for none
	 */
	public function save(array $servers = [], ?array $autodiscover = null, ?string $visio = null): Response {
		return $this->handle(function () use ($servers, $autodiscover, $visio) {
			if ($visio !== null) {
				$this->servers->setVisio($visio);
			}
			$this->servers->setServers($servers);
			if ($autodiscover !== null) {
				$this->servers->setAutodiscover(!empty($autodiscover['enabled']), (array)($autodiscover['hosts'] ?? []));
			}
			return $this->state();
		});
	}

	/** A domain found by Autodiscover is forgotten: the next account of this domain looks again. */
	public function forgetDiscovered(string $domain): Response {
		return $this->handle(function () use ($domain) {
			$this->servers->forgetDiscovered($domain);
			return $this->state();
		});
	}

	private function state(): array {
		return [
			'servers' => $this->servers->getServers(),
			'autodiscover' => $this->servers->getAutodiscover(),
			'discovered' => $this->servers->getDiscovered(),
			'visio' => $this->servers->getVisio(),
		];
	}
}
