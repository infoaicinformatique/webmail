<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\AttachmentRules;
use OCA\Webmail\Service\AutoFileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The rules « attachments of this sender → this folder of Files » of an account.
 */
class AutoFileController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private AutoFileService $autoFile,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	#[NoAdminRequired]
	public function index(int $id): Response {
		return $this->handle(fn () => ['rules' => $this->autoFile->rules($this->accounts->find((string)$this->userId, $id))]);
	}

	#[NoAdminRequired]
	public function add(int $id, string $from, string $folder): Response {
		return $this->handle(function () use ($id, $from, $folder) {
			$account = $this->accounts->find((string)$this->userId, $id);
			return ['rules' => $this->autoFile->save($account, AttachmentRules::add($this->autoFile->rules($account), $from, $folder))];
		});
	}

	#[NoAdminRequired]
	public function remove(int $id, string $from): Response {
		return $this->handle(function () use ($id, $from) {
			$account = $this->accounts->find((string)$this->userId, $id);
			$from = mb_strtolower(trim($from));
			return ['rules' => $this->autoFile->save($account, array_values(array_filter($this->autoFile->rules($account), fn ($r) => $r['from'] !== $from)))];
		});
	}
}
