<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\Newsletters;
use OCA\Webmail\Service\UnsubscribeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * « Lettres d'information »: the mailing lists of the inbox, one row per sender; leave
 * one and delete its messages in one click, or keep it (no longer shown).
 */
class NewsletterController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private UnsubscribeService $unsubscribe,
		private IConfig $config,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	/** @return list<string> */
	private function kept(int $id): array {
		$this->accounts->find((string)$this->userId, $id);
		return Newsletters::decodeKept($this->config->getUserValue((string)$this->userId, Application::APP_ID, Newsletters::PREF . $id, '[]'));
	}

	#[NoAdminRequired]
	public function index(int $id): Response {
		return $this->handle(function () use ($id) {
			$kept = $this->kept($id);
			$client = $this->accounts->getClient($this->accounts->find((string)$this->userId, $id));
			$found = $client->mailingListMessages('inbox');
			return [
				'senders' => Newsletters::group($found['messages'], $kept),
				'scanned' => $found['scanned'],
				'max' => EwsClient::LISTS_SCAN,
				'kept' => $kept,
			];
		});
	}

	/** « Garder »: a list wanted, no longer shown (or shown again). */
	#[NoAdminRequired]
	public function keep(int $id, string $email, bool $keep = true): Response {
		return $this->handle(function () use ($id, $email, $keep) {
			try {
				$kept = Newsletters::keep($this->kept($id), $email, $keep);
			} catch (\InvalidArgumentException) {
				throw new MailException('Adresse invalide : %s', [$email]);
			}
			if ($kept === []) {
				$this->config->deleteUserValue((string)$this->userId, Application::APP_ID, Newsletters::PREF . $id);
			} else {
				$this->config->setUserValue((string)$this->userId, Application::APP_ID, Newsletters::PREF . $id, (string)json_encode($kept));
			}
			return ['kept' => $kept];
		});
	}

	/**
	 * « Se désabonner et tout supprimer »: the list left the way it asks (from its newest
	 * message), then every message of the sender in the inbox deleted. Nothing is deleted
	 * when the list refuses. « link »: its page is to be opened, the messages are deleted.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 300)]
	public function clean(int $id, string $email, string $itemId, bool $unsubscribe = true): Response {
		return $this->handle(function () use ($id, $email, $itemId, $unsubscribe) {
			$client = $this->accounts->getClient($this->accounts->find((string)$this->userId, $id));
			$done = $unsubscribe ? $this->unsubscribe->unsubscribe($client, $itemId) : null;
			$ids = $client->idsFromSender('inbox', $email);
			foreach (array_chunk($ids, 100) as $chunk) {
				$client->deleteItems($chunk);
			}
			return ['count' => count($ids), 'itemIds' => $ids, 'unsubscribe' => $done];
		});
	}
}
