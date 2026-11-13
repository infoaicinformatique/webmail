<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\TemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Reply templates of the Nextcloud user.
 */
class TemplateController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private TemplateService $templates,
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
		return $this->handle(fn () => ['templates' => $this->templates->list((string)$this->userId)]);
	}

	#[NoAdminRequired]
	public function save(?string $id = null, string $name = '', string $html = ''): Response {
		return $this->handle(fn () => ['templates' => $this->templates->save((string)$this->userId, $id !== '' ? $id : null, $name, $html)]);
	}

	#[NoAdminRequired]
	public function delete(string $id): Response {
		return $this->handle(fn () => ['templates' => $this->templates->delete((string)$this->userId, $id)]);
	}
}
