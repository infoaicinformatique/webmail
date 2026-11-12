<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsRules;
use OCA\Webmail\Service\AccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Inbox rules of an account. Every change answers with the new list.
 */
class RuleController extends Controller {
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

	private function rules(int $id): EwsRules {
		$account = $this->accounts->find((string)$this->userId, $id);
		return new EwsRules($this->accounts->getClient($account), $account->getEmail());
	}

	#[NoAdminRequired]
	public function index(int $id): Response {
		return $this->handle(fn () => ['rules' => $this->rules($id)->list()]);
	}

	/**
	 * @param array<string, mixed> $conditions
	 * @param array<string, mixed> $actions
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 300)]
	public function save(int $id, string $name, array $conditions = [], array $actions = [], string $ruleId = '', bool $enabled = true): Response {
		return $this->handle(function () use ($id, $name, $conditions, $actions, $ruleId, $enabled) {
			$rules = $this->rules($id);
			$rules->save(['id' => $ruleId, 'name' => $name, 'enabled' => $enabled, 'conditions' => $conditions, 'actions' => $actions]);
			return ['rules' => $rules->list()];
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 300)]
	public function enable(int $id, string $ruleId, bool $enabled): Response {
		return $this->handle(function () use ($id, $ruleId, $enabled) {
			$rules = $this->rules($id);
			$rules->setEnabled($ruleId, $enabled);
			return ['rules' => $rules->list()];
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 300)]
	public function move(int $id, string $ruleId, int $priority): Response {
		return $this->handle(function () use ($id, $ruleId, $priority) {
			$rules = $this->rules($id);
			$rules->setPriority($ruleId, $priority);
			return ['rules' => $rules->list()];
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 300)]
	public function delete(int $id, string $ruleId): Response {
		return $this->handle(function () use ($id, $ruleId) {
			$rules = $this->rules($id);
			$rules->delete($ruleId);
			return ['rules' => $rules->list()];
		});
	}
}
