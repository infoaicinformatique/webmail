<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\MatrixService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The user's Matrix account (Element…): « Envoyer sur Matrix ».
 */
class MatrixController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private MatrixService $matrix,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	/** The account (never its token). */
	#[NoAdminRequired]
	public function index(): Response {
		return $this->handle(fn () => $this->matrix->settings((string)$this->userId));
	}

	#[NoAdminRequired]
	public function save(string $homeserver, string $token = '', string $room = ''): Response {
		return $this->handle(fn () => $this->matrix->save((string)$this->userId, $homeserver, $token, $room));
	}

	#[NoAdminRequired]
	public function alerts(string $alerts): Response {
		return $this->handle(fn () => $this->matrix->setAlerts((string)$this->userId, $alerts));
	}

	#[NoAdminRequired]
	public function forget(): Response {
		return $this->handle(function () {
			$this->matrix->forget((string)$this->userId);
			return $this->matrix->settings((string)$this->userId);
		});
	}

	#[NoAdminRequired]
	public function rooms(): Response {
		return $this->handle(fn () => ['rooms' => $this->matrix->rooms((string)$this->userId)]);
	}

	#[NoAdminRequired]
	public function send(string $text, string $room = ''): Response {
		return $this->handle(fn () => $this->matrix->send((string)$this->userId, $room, $text));
	}
}
