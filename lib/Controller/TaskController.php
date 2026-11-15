<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Calendar\ExchangeTasksCalendar;
use OCA\Webmail\Ews\EwsTasks;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\MeetingReminderService;
use OCA\Webmail\Service\NcCalendarRules;
use OCA\Webmail\Service\NextcloudTasksService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The tasks of an account: its Exchange lists, and the lists of the Tasks app of Nextcloud.
 */
class TaskController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private MeetingReminderService $reminders,
		private NextcloudTasksService $nextcloud,
		private IConfig $config,
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

	/** A list of the Tasks app of Nextcloud (« nc:<id> »): the account is still checked to be the user's. */
	private function isNextcloud(int $id, string $list): bool {
		if (!NcCalendarRules::isKey($list)) {
			return false;
		}
		$this->accounts->find((string)$this->userId, $id);
		return true;
	}

	/** The task lists: those of the mailbox (« Tâches » and the other folders of tasks), then those of Nextcloud. */
	#[NoAdminRequired]
	public function lists(int $id): Response {
		return $this->handle(function () use ($id) {
			$lists = array_map(fn ($l) => $l + ['kind' => 'exchange', 'canEdit' => true], $this->tasks($id)->lists());
			try {
				$lists = array_merge($lists, $this->nextcloud->lists((string)$this->userId));
			} catch (\Throwable $e) {
				$this->logger->warning('Webmail: Nextcloud task lists not listed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
			return ['lists' => $lists];
		});
	}

	#[NoAdminRequired]
	public function index(int $id, bool $completed = false, string $list = 'tasks'): Response {
		return $this->handle(fn () => ['tasks' => $this->isNextcloud($id, $list)
			? $this->nextcloud->tasks((string)$this->userId, $list, $completed)
			: $this->tasks($id)->list($completed, $list)]);
	}

	#[NoAdminRequired]
	public function show(int $id, string $taskId, string $list = 'tasks'): Response {
		return $this->handle(fn () => $this->isNextcloud($id, $list)
			? $this->nextcloud->get((string)$this->userId, $list, $taskId)
			: $this->tasks($id)->get($taskId));
	}

	/** @param array<string, mixed> $task */
	#[NoAdminRequired]
	public function create(int $id, array $task, string $list = 'tasks'): Response {
		return $this->handle(function () use ($id, $task, $list) {
			if ($this->isNextcloud($id, $list)) {
				return ['id' => $this->nextcloud->create((string)$this->userId, $list, $task)];
			}
			$taskId = $this->tasks($id)->create($task, $list);
			$this->reminders->tasksChanged($id);
			return ['id' => $taskId];
		});
	}

	/** @param array<string, mixed> $task */
	#[NoAdminRequired]
	public function update(int $id, string $taskId, array $task, string $list = 'tasks'): Response {
		return $this->handle(function () use ($id, $taskId, $task, $list) {
			if ($this->isNextcloud($id, $list)) {
				$this->nextcloud->update((string)$this->userId, $list, $taskId, $task);
				return ['status' => 'ok'];
			}
			$this->tasks($id)->update($taskId, $task);
			$this->reminders->tasksChanged($id);
			return ['status' => 'ok'];
		});
	}

	#[NoAdminRequired]
	public function complete(int $id, string $taskId, bool $done = true, string $list = 'tasks'): Response {
		return $this->handle(function () use ($id, $taskId, $done, $list) {
			if ($this->isNextcloud($id, $list)) {
				$this->nextcloud->complete((string)$this->userId, $list, $taskId, $done);
				return ['status' => 'ok'];
			}
			$this->tasks($id)->complete($taskId, $done);
			$this->reminders->tasksChanged($id);
			return ['status' => 'ok'];
		});
	}

	#[NoAdminRequired]
	public function delete(int $id, string $taskId, string $list = 'tasks'): Response {
		return $this->handle(function () use ($id, $taskId, $list) {
			if ($this->isNextcloud($id, $list)) {
				$this->nextcloud->delete((string)$this->userId, $list, $taskId);
				return ['status' => 'ok'];
			}
			$this->tasks($id)->delete($taskId);
			$this->reminders->tasksChanged($id);
			return ['status' => 'ok'];
		});
	}

	/** « Visible dans Tâches de Nextcloud »: the Exchange lists of this account in the Tasks app (read only). */
	#[NoAdminRequired]
	public function shownInNextcloud(int $id): Response {
		return $this->handle(function () use ($id) {
			$this->accounts->find((string)$this->userId, $id);
			return ['enabled' => $this->config->getUserValue((string)$this->userId, Application::APP_ID, ExchangeTasksCalendar::settingKey($id), '') === '1'];
		});
	}

	#[NoAdminRequired]
	public function showInNextcloud(int $id, bool $enabled): Response {
		return $this->handle(function () use ($id, $enabled) {
			$this->accounts->find((string)$this->userId, $id);
			if ($enabled) {
				$this->config->setUserValue((string)$this->userId, Application::APP_ID, ExchangeTasksCalendar::settingKey($id), '1');
			} else {
				$this->config->deleteUserValue((string)$this->userId, Application::APP_ID, ExchangeTasksCalendar::settingKey($id));
			}
			return ['enabled' => $enabled];
		});
	}
}
