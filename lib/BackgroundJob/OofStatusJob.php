<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\OofStatusService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Every hour, the status of Nextcloud after the automatic replies of Exchange (set in Outlook,
 * or dates reached).
 */
class OofStatusJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private OofStatusService $service,
		private \OCA\Webmail\Service\AbsenceReplyService $absences,
		private \OCP\IUserManager $users,
		private \OCA\Webmail\Db\AccountMapper $mapper,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->service->syncAll();
		// an absence of Nextcloud under way whose replies were never scheduled
		if (!interface_exists(\OCP\User\IAvailabilityCoordinator::class)) {
			return;
		}
		$coordinator = \OCP\Server::get(\OCP\User\IAvailabilityCoordinator::class);
		if (!$coordinator->isEnabled()) {
			return;
		}
		$seen = [];
		foreach ($this->mapper->findAll() as $account) {
			$userId = $account->getUserId();
			if (isset($seen[$userId]) || !$this->absences->isEnabled($userId)) {
				continue;
			}
			$seen[$userId] = true;
			$user = $this->users->get($userId);
			$data = $user !== null && $user->isEnabled() ? $coordinator->getCurrentOutOfOfficeData($user) : null;
			if ($data !== null) {
				$this->absences->catchUp($data);
			}
		}
	}
}
