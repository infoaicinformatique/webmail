<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\Backup;
use OCA\Webmail\Service\BackupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** « Sauvegarde dans Fichiers » of an account. */
class BackupController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private BackupService $backup,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	/** what the browser is told (not the cursors) */
	private static function shown(array $s): array {
		return ['on' => $s['on'], 'path' => $s['path'], 'folders' => $s['folders'], 'last' => $s['last'], 'total' => $s['total'], 'from' => Backup::FROM];
	}

	#[NoAdminRequired]
	public function index(int $id): Response {
		return $this->handle(fn () => self::shown($this->backup->settings($this->accounts->find((string)$this->userId, $id))));
	}

	/** @param list<string> $folders */
	#[NoAdminRequired]
	public function save(int $id, bool $on = false, string $path = Backup::DEFAULT_PATH, array $folders = [], int $fromDays = 0): Response {
		return $this->handle(fn () => self::shown($this->backup->save($this->accounts->find((string)$this->userId, $id), $on, $path, $folders, $fromDays)));
	}

	/** « Sauvegarder maintenant » */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	public function now(int $id): Response {
		return $this->handle(function () use ($id) {
			$account = $this->accounts->find((string)$this->userId, $id);
			$saved = $this->backup->run($account);
			return ['saved' => $saved] + self::shown($this->backup->settings($account));
		});
	}
}
