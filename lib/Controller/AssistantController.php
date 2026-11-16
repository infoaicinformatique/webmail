<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsOof;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\AssistantService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** The Assistant of Nextcloud on a message: summary, reply, translation. */
class AssistantController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private AssistantService $assistant,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	#[NoAdminRequired]
	public function capabilities(): Response {
		return $this->handle(fn () => $this->assistant->capabilities((string)$this->userId));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 3600)]
	public function start(int $id, string $itemId, string $kind, string $instructions = '', string $language = ''): Response {
		return $this->handle(function () use ($id, $itemId, $kind, $instructions, $language) {
			$client = $this->accounts->getClient($this->accounts->find((string)$this->userId, $id));
			$m = $client->getItem($itemId);
			$text = ($m['bodyType'] ?? 'HTML') === 'Text' ? (string)$m['body'] : EwsOof::htmlToText((string)$m['body']);
			return ['taskId' => $this->assistant->start((string)$this->userId, $kind, (string)$m['subject'], $text, mb_substr($instructions, 0, 500), $language)];
		});
	}

	#[NoAdminRequired]
	public function result(int $taskId): Response {
		return $this->handle(fn () => $this->assistant->result((string)$this->userId, $taskId));
	}
}
