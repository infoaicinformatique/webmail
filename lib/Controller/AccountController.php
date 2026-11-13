<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\MailNotificationService;
use OCA\Webmail\Service\MeetingReminderService;
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
		private MailNotificationService $bell,
		private MeetingReminderService $reminders,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	/** The account, and whether new messages are announced in Nextcloud's notifications. */
	private function out(Account $a): array {
		return self::describe($a, $this->bell, $this->reminders, (string)$this->userId);
	}

	/** An account as the webmail gets it (also given with the page, so that it starts without asking). */
	public static function describe(Account $a, MailNotificationService $bell, MeetingReminderService $reminders, string $userId): array {
		return $a->jsonSerialize() + [
			'bell' => $bell->isEnabled($userId, $a->getId()),
			'reminders' => $reminders->isEnabled($userId, $a->getId()),
		];
	}

	#[NoAdminRequired]
	public function index(): Response {
		return $this->handle(fn () => array_map(fn ($a) => $this->out($a), $this->accounts->findAll((string)$this->userId)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	public function create(string $email, string $password, ?string $displayName = null): Response {
		return $this->handle(fn () => $this->out($this->accounts->create((string)$this->userId, $email, $password, $displayName)));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	public function update(int $id, ?string $displayName = null, ?string $password = null, ?string $signature = null, ?bool $bell = null, ?bool $reminders = null): Response {
		return $this->handle(function () use ($id, $displayName, $password, $signature, $bell, $reminders) {
			$account = $this->accounts->update((string)$this->userId, $id, $displayName, $password, $signature);
			if ($bell !== null) {
				$this->bell->setEnabled((string)$this->userId, $id, $bell);
			}
			if ($reminders !== null) {
				$this->reminders->setEnabled((string)$this->userId, $id, $reminders);
			}
			return $this->out($account);
		});
	}

	#[NoAdminRequired]
	public function destroy(int $id): Response {
		return $this->handle(function () use ($id) {
			$this->accounts->delete((string)$this->userId, $id);
			$this->bell->forget((string)$this->userId, $id);
			$this->reminders->forget((string)$this->userId, $id);
			return ['status' => 'ok'];
		});
	}
}
