<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsTasks;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\MeetingReminderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Exchange tasks of an account.
 */
class TaskController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private MeetingReminderService $reminders,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	private function tasks(int $id): EwsTasks {
		return new EwsTasks($this->accounts->getClient($this->accounts->find((string)$this->userId, $id)));
	}

	#[NoAdminRequired]
	public function index(int $id, bool $completed = false): Response {
		return $this->handle(fn () => ['tasks' => $this->tasks($id)->list($completed)]);
	}

	#[NoAdminRequired]
	public function show(int $id, string $taskId): Response {
		return $this->handle(fn () => $this->tasks($id)->get($taskId));
	}

	/** @param array<string, mixed> $task */
	#[NoAdminRequired]
	public function create(int $id, array $task): Response {
		return $this->handle(function () use ($id, $task) {
			$taskId = $this->tasks($id)->create($task);
			$this->reminders->tasksChanged($id);
			return ['id' => $taskId];
		});
	}

	/** @param array<string, mixed> $task */
	#[NoAdminRequired]
	public function update(int $id, string $taskId, array $task): Response {
		return $this->handle(function () use ($id, $taskId, $task) {
			$this->tasks($id)->update($taskId, $task);
			$this->reminders->tasksChanged($id);
			return ['status' => 'ok'];
		});
	}

	#[NoAdminRequired]
	public function complete(int $id, string $taskId, bool $done = true): Response {
		return $this->handle(function () use ($id, $taskId, $done) {
			$this->tasks($id)->complete($taskId, $done);
			$this->reminders->tasksChanged($id);
			return ['status' => 'ok'];
		});
	}

	#[NoAdminRequired]
	public function delete(int $id, string $taskId): Response {
		return $this->handle(function () use ($id, $taskId) {
			$this->tasks($id)->delete($taskId);
			$this->reminders->tasksChanged($id);
			return ['status' => 'ok'];
		});
	}
}
