<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsCategories;
use OCA\Webmail\Service\AccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Outlook categories: the list of the mailbox, and those of messages.
 */
class CategoryController extends Controller {
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

	private function categories(int $id): EwsCategories {
		return new EwsCategories($this->accounts->getClient($this->accounts->find((string)$this->userId, $id)));
	}

	#[NoAdminRequired]
	public function index(int $id): Response {
		return $this->handle(fn () => ['categories' => $this->categories($id)->list(), 'colors' => EwsCategories::COLORS, 'colorNames' => EwsCategories::COLOR_NAMES]);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 300)]
	public function create(int $id, string $name, int $color = -1): Response {
		return $this->handle(function () use ($id, $name, $color) {
			$categories = $this->categories($id);
			$categories->add($name, $color);
			return ['categories' => $categories->list()];
		});
	}

	/**
	 * @param string[] $itemIds
	 * @param string[] $categories the categories the messages will have
	 */
	#[NoAdminRequired]
	public function assign(int $id, array $itemIds, array $categories = []): Response {
		return $this->handle(function () use ($id, $itemIds, $categories) {
			$this->accounts->getClient($this->accounts->find((string)$this->userId, $id))->setCategories($itemIds, $categories);
			return ['status' => 'ok'];
		});
	}
}
