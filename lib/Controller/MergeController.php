<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\MergeRules;
use OCA\Webmail\Service\MergeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** « Envoi personnalisé »: the list read, the sending begun, followed, stopped. */
class MergeController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private MergeService $merges,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	/** The list as read (recipients, columns, lines refused), and the first message as it will go. */
	#[NoAdminRequired]
	public function preview(int $id, string $list = '', string $subject = '', string $html = ''): Response {
		return $this->handle(function () use ($id, $list, $subject, $html) {
			$this->accounts->find((string)$this->userId, $id);
			$p = MergeRules::parse($list);
			$first = $p['rows'][0] ?? null;
			return [
				'count' => count($p['rows']),
				'max' => MergeRules::MAX,
				'columns' => $p['columns'],
				'refused' => array_slice($p['refused'], 0, 20),
				'refusedCount' => count($p['refused']),
				'first' => $first === null ? null : [
					'email' => $first['email'],
					'subject' => MergeRules::fill($subject, $first, false),
					'html' => MergeRules::fill($html, $first, true),
				],
			];
		});
	}

	#[NoAdminRequired]
	public function index(int $id): Response {
		return $this->handle(fn () => ['sendings' => array_map([MergeService::class, 'report'], $this->merges->sendings($this->accounts->find((string)$this->userId, $id)))]);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 3600)]
	public function start(int $id, string $subject = '', string $html = '', string $list = ''): Response {
		return $this->handle(fn () => $this->merges->start($this->accounts->find((string)$this->userId, $id), $subject, $html, $list));
	}

	#[NoAdminRequired]
	public function stop(int $id, string $sendingId): Response {
		return $this->handle(fn () => $this->merges->stop($this->accounts->find((string)$this->userId, $id), $sendingId));
	}
}
