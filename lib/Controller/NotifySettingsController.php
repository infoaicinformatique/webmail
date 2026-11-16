<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\Digest;
use OCA\Webmail\Service\DigestService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** The user's notification settings of the webmail, all accounts together: « Résumé du matin ». */
class NotifySettingsController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private DigestService $digests,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	private function settings(): array {
		return ['digestHour' => $this->digests->hour((string)$this->userId), 'hours' => Digest::HOURS];
	}

	#[NoAdminRequired]
	public function index(): Response {
		return $this->handle(fn () => $this->settings());
	}

	#[NoAdminRequired]
	public function save(int $digestHour = 0): Response {
		return $this->handle(function () use ($digestHour) {
			if (!Digest::validHour($digestHour)) {
				throw new MailException('Heure invalide');
			}
			$this->digests->setHour((string)$this->userId, $digestHour);
			return $this->settings();
		});
	}

	/** « Recevoir le résumé maintenant »: today's summary in the bell at once. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	public function digestNow(): Response {
		return $this->handle(fn () => ['sent' => $this->digests->send((string)$this->userId)]);
	}
}
