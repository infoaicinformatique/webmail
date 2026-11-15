<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\HealthService;
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
		private HealthService $health,
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

	/** « État des services »: checked now. */
	public function health(): Response {
		return $this->handle(fn () => ['checks' => $this->health->checks()]);
	}

	/**
	 * @param array<int, array<string, string>> $servers
	 * @param ?array{enabled?: bool, hosts?: string[]} $autodiscover
	 * @param ?string $visio the address of the video meetings (Element Call…), '' for none
	 * @param ?bool $verifyTls whether the certificates of the servers are checked
	 * @param ?int $maxAttachmentsMb most attachments of one message (MB)
	 */
	public function save(array $servers = [], ?array $autodiscover = null, ?string $visio = null, ?bool $verifyTls = null, ?int $maxAttachmentsMb = null, ?string $visioMode = null): Response {
		return $this->handle(function () use ($servers, $autodiscover, $visio, $verifyTls, $maxAttachmentsMb, $visioMode) {
			if ($visioMode !== null) {
				if ($visioMode === ServerConfigService::VISIO_ELEMENT_ROOM && trim((string)$visio) === '') {
					throw new \OCA\Webmail\Exception\MailException('Indiquez l\'adresse de votre Element (par exemple %s)', ['https://chat.example.org']);
				}
				$this->servers->setVisioMode($visioMode);
			}
			// checked first: nothing is saved when the size is refused
			if ($maxAttachmentsMb !== null) {
				$this->servers->setMaxAttachmentsMb($maxAttachmentsMb);
			}
			if ($verifyTls !== null) {
				if ($verifyTls !== $this->servers->getVerifyTls()) {
					$this->logger->warning('Webmail: certificate checks of the Exchange and Matrix servers ' . ($verifyTls ? 'enabled' : 'DISABLED') . ' by an administrator');
				}
				$this->servers->setVerifyTls($verifyTls);
			}
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
			'visioMode' => $this->servers->getVisioMode(),
			'verifyTls' => $this->servers->getVerifyTls(),
			'maxAttachmentsMb' => $this->servers->getMaxAttachmentsMb(),
		];
	}
}
