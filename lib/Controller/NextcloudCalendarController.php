<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Calendar\ExchangeCalendarProvider;
use OCA\Webmail\Service\AccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** « Visible dans l'Agenda de Nextcloud »: the Exchange calendar shown in the Calendar app. */
class NextcloudCalendarController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private IConfig $config,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	#[NoAdminRequired]
	public function status(int $id): Response {
		return $this->handle(function () use ($id) {
			$this->accounts->find((string)$this->userId, $id);
			return new JSONResponse(['enabled' => $this->config->getUserValue((string)$this->userId, Application::APP_ID, ExchangeCalendarProvider::settingKey($id), '') === '1']);
		});
	}

	#[NoAdminRequired]
	public function set(int $id, bool $enabled): Response {
		return $this->handle(function () use ($id, $enabled) {
			$this->accounts->find((string)$this->userId, $id);
			if ($enabled) {
				$this->config->setUserValue((string)$this->userId, Application::APP_ID, ExchangeCalendarProvider::settingKey($id), '1');
			} else {
				$this->config->deleteUserValue((string)$this->userId, Application::APP_ID, ExchangeCalendarProvider::settingKey($id));
			}
			return new JSONResponse(['enabled' => $enabled]);
		});
	}
}
