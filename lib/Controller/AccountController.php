<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AccountController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
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
		return $this->handle(fn () => array_map(fn ($a) => $a->jsonSerialize(), $this->accounts->findAll((string)$this->userId)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	public function create(string $email, string $password, ?string $displayName = null): Response {
		return $this->handle(fn () => $this->accounts->create((string)$this->userId, $email, $password, $displayName)->jsonSerialize());
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	public function update(int $id, ?string $displayName = null, ?string $password = null, ?string $signature = null): Response {
		return $this->handle(fn () => $this->accounts->update((string)$this->userId, $id, $displayName, $password, $signature)->jsonSerialize());
	}

	#[NoAdminRequired]
	public function destroy(int $id): Response {
		return $this->handle(function () use ($id) {
			$this->accounts->delete((string)$this->userId, $id);
			return ['status' => 'ok'];
		});
	}
}
