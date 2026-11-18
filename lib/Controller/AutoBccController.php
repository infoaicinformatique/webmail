<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\AutoBcc;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** « Copie cachée automatique » of an account. */
class AutoBccController extends Controller {
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
	public function index(int $id): Response {
		return $this->handle(function () use ($id) {
			$this->accounts->find((string)$this->userId, $id);
			return ['rules' => AutoBcc::decode($this->config->getUserValue((string)$this->userId, Application::APP_ID, AutoBcc::PREF . $id, '[]'))];
		});
	}

	/** @param array<int, array<string, mixed>> $rules all of them (replaced) */
	#[NoAdminRequired]
	public function save(int $id, array $rules = []): Response {
		return $this->handle(function () use ($id, $rules) {
			$this->accounts->find((string)$this->userId, $id);
			try {
				$list = AutoBcc::validate(array_values($rules));
			} catch (\InvalidArgumentException $e) {
				$msg = $e->getMessage();
				throw match (true) {
					$msg === 'too many' => new MailException('Trop de copies cachées automatiques (%s au plus)', [AutoBcc::MAX]),
					$msg === 'domains' => new MailException('Indiquez au moins un domaine, par exemple client.fr'),
					default => new MailException('Adresse invalide : %s', [substr($msg, 8)]),
				};
			}
			if ($list === []) {
				$this->config->deleteUserValue((string)$this->userId, Application::APP_ID, AutoBcc::PREF . $id);
			} else {
				$this->config->setUserValue((string)$this->userId, Application::APP_ID, AutoBcc::PREF . $id, (string)json_encode($list));
			}
			return ['rules' => $list];
		});
	}
}
