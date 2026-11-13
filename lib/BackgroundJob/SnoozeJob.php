<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\SnoozeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Every 5 minutes (with Nextcloud's cron), the messages put aside (« Reporter »)
 * whose time has come go back to their inbox, unread.
 */
class SnoozeJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private SnoozeService $service,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->service->wakeAll();
	}
}
