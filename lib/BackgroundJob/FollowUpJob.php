<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\FollowUpService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Every 15 minutes (with Nextcloud's cron), the messages sent waiting for an answer whose
 * time has come: a notification if nobody answered.
 */
class FollowUpJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private FollowUpService $service,
	) {
		parent::__construct($time);
		$this->setInterval(900);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->service->checkAll();
	}
}
