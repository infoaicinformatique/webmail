<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * « Tous ses messages »: the messages of one sender in a folder, counted,
 * then deleted, moved or marked as read in one go.
 */
class SenderController extends Controller {
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

	private function client(int $id): EwsClient {
		return $this->accounts->getClient($this->accounts->find((string)$this->userId, $id));
	}

	#[NoAdminRequired]
	public function count(int $id, string $folderId, string $email): Response {
		return $this->handle(function () use ($id, $folderId, $email) {
			$ids = $this->client($id)->idsFromSender($folderId, $email);
			return new JSONResponse(['count' => count($ids), 'max' => EwsClient::SENDER_MAX, 'query' => EwsClient::fromQuery($email)]);
		});
	}

	/** @param string $action delete | move | read */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 300)]
	public function apply(int $id, string $folderId, string $email, string $action, string $target = ''): Response {
		return $this->handle(function () use ($id, $folderId, $email, $action, $target) {
			$client = $this->client($id);
			$ids = $client->idsFromSender($folderId, $email);
			foreach (array_chunk($ids, 100) as $chunk) {
				match ($action) {
					'delete' => $client->deleteItems($chunk),
					'move' => $target !== '' && $target !== $folderId ? $client->moveItems($chunk, $target) : throw new MailException('Choisissez un autre dossier'),
					'read' => $client->setRead($chunk, true),
					default => throw new MailException('Action inconnue'),
				};
			}
			return new JSONResponse(['count' => count($ids), 'itemIds' => $ids]);
		});
	}
}
