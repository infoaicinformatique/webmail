<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\Focus;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** « Prioritaires / Autres »: the senders the user put in a tab for good. */
class FocusController extends Controller {
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
			return Focus::decode($this->config->getUserValue((string)$this->userId, Application::APP_ID, Focus::PREF . $id, '{}'));
		});
	}

	/** where: 'priority', 'other', or '' (back to the automatic choice) */
	#[NoAdminRequired]
	public function set(int $id, string $email, string $where = ''): Response {
		return $this->handle(function () use ($id, $email, $where) {
			$this->accounts->find((string)$this->userId, $id);
			$o = Focus::decode($this->config->getUserValue((string)$this->userId, Application::APP_ID, Focus::PREF . $id, '{}'));
			try {
				$o = Focus::set($o, $email, $where);
			} catch (\InvalidArgumentException $e) {
				throw match ($e->getMessage()) {
					'too many' => new MailException('Trop d\'expéditeurs classés (%s au plus)', [Focus::MAX]),
					'where' => new MailException('Onglet inconnu'),
					default => new MailException('Adresse invalide : %s', [$email]),
				};
			}
			if ($o['priority'] === [] && $o['other'] === []) {
				$this->config->deleteUserValue((string)$this->userId, Application::APP_ID, Focus::PREF . $id);
			} else {
				$this->config->setUserValue((string)$this->userId, Application::APP_ID, Focus::PREF . $id, (string)json_encode($o));
			}
			return $o;
		});
	}
}
