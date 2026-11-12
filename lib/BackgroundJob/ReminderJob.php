<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\MeetingReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Every minute (as often as Nextcloud's cron runs), the calendar reminders
 * that are due become notifications.
 */
class ReminderJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private MeetingReminderService $service,
	) {
		parent::__construct($time);
		$this->setInterval(60);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
		// a run still going (slow server) is not started again beside it
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->service->checkAll();
	}
}
