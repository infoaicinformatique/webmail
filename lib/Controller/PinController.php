<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\PinList;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** « Épingler »: messages kept at the top of their folder, whatever the order. */
class PinController extends Controller {
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

	private function key(int $id): string {
		return 'pinned_' . $id;
	}

	/** @return list<string> */
	private function list(int $id): array {
		return PinList::decode($this->config->getUserValue((string)$this->userId, Application::APP_ID, $this->key($id), '[]'));
	}

	/** @param list<string> $list */
	private function save(int $id, array $list): void {
		if ($list === []) {
			$this->config->deleteUserValue((string)$this->userId, Application::APP_ID, $this->key($id));
		} else {
			$this->config->setUserValue((string)$this->userId, Application::APP_ID, $this->key($id), json_encode($list));
		}
	}

	/** The pinned messages still there (with their folder), the last pinned first. */
	#[NoAdminRequired]
	public function index(int $id): Response {
		return $this->handle(function () use ($id) {
			$account = $this->accounts->find((string)$this->userId, $id);
			$list = $this->list($id);
			if ($list === []) {
				return new JSONResponse(['messages' => []]);
			}
			$found = $this->accounts->getClient($account)->summaries($list);
			$byId = [];
			foreach ($found as $m) {
				$byId[(string)$m['id']] = $m;
			}
			$kept = PinList::keep($list, array_keys($byId));
			if ($kept !== $list) {
				$this->save($id, $kept);
			}
			return new JSONResponse(['messages' => array_map(fn ($i) => $byId[$i], $kept)]);
		});
	}

	#[NoAdminRequired]
	public function set(int $id, string $itemId, bool $pinned): Response {
		return $this->handle(function () use ($id, $itemId, $pinned) {
			$this->accounts->find((string)$this->userId, $id);
			$list = PinList::set($this->list($id), $itemId, $pinned);
			$this->save($id, $list);
			return new JSONResponse(['pinned' => $pinned, 'count' => count($list)]);
		});
	}
}
