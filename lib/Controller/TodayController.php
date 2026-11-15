<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\TimeZones;
use OCA\Webmail\Service\TodayService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** « Aujourd'hui »: the meetings, tasks, follow-ups and snoozed messages of the day. */
class TodayController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private TodayService $today,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	#[NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\UserRateLimit(limit: 60, period: 300)]
	public function index(string $tz = 'UTC'): Response {
		return $this->handle(fn () => $this->today->today((string)$this->userId, TimeZones::isValidIana($tz) ? $tz : 'UTC'));
	}
}
